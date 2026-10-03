<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Server\FrankenBinary;
use Celema\Server\FrankenCache;
use Celema\Server\FrankenInstall;
use Celema\Server\FrankenPhp;
use Celema\Server\FrankenReleases;
use Celema\Server\Ports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Installs FrankenPHP from a stand-in for GitHub's release API, whose
 * builds are shell scripts that report their version.
 */
final class FrankenInstallTest extends TestCase
{
	private const array ENV = ['CELEMA_FRANKENPHP_RELEASES', 'CELEMA_FRANKENPHP_DIR', 'GITHUB_TOKEN', 'PATH'];

	private string $dir = '';
	private string $cache = '';
	private string $asset = '';

	/** @var resource|null */
	private mixed $server = null;

	/** @var array<string, string|false> */
	private array $env = [];

	protected function setUp(): void
	{
		$asset = FrankenReleases::asset(PHP_OS_FAMILY, php_uname('m'));

		if ($asset === null) {
			$this->markTestSkipped('No FrankenPHP build is published for this platform.');
		}

		$this->asset = $asset;
		$this->dir = sys_get_temp_dir() . '/celema-franken-' . bin2hex(random_bytes(4));
		$this->cache = "{$this->dir}/cache";
		mkdir("{$this->dir}/bin", recursive: true);

		foreach (self::ENV as $name) {
			$this->env[$name] = getenv($name);
		}

		$port = $this->serve();
		putenv("CELEMA_FRANKENPHP_RELEASES=http://127.0.0.1:{$port}/releases");
		putenv("CELEMA_FRANKENPHP_DIR={$this->cache}");
		putenv('GITHUB_TOKEN=secret');
		// Only the stand-in on PATH, if a test adds it.
		putenv("PATH={$this->dir}/bin");
	}

	protected function tearDown(): void
	{
		foreach ($this->env as $name => $value) {
			putenv($value === false ? $name : "{$name}={$value}");
		}

		if ($this->server !== null) {
			proc_terminate($this->server);
			proc_close($this->server);
		}

		if ($this->dir !== '') {
			exec('rm -rf ' . escapeshellarg($this->dir));
		}
	}

	public function testInstallsTheLatestRelease(): void
	{
		[$exit, $io] = $this->install();

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString("Installed FrankenPHP 9.9.9: {$this->cache}/9.9.9/frankenphp", $io->output());
		$this->assertExecutable("{$this->cache}/9.9.9/frankenphp");
		// The token goes to the API only, and the download follows its redirect.
		$this->assertSame(
			[
				'GET /releases/latest token',
				"GET /download/v9.9.9/{$this->asset} -",
				'GET /asset/9.9.9 -',
			],
			$this->requests(),
		);
	}

	public function testInstallsAVersionWithoutPublishedChecksum(): void
	{
		[$exit, $io] = $this->install('v9.9.7');

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString('has no published checksum', $io->errorOutput());
		$this->assertExecutable("{$this->cache}/9.9.7/frankenphp");
	}

	public function testRejectsADownloadThatDoesNotMatchItsChecksum(): void
	{
		[$exit, $io] = $this->install('9.9.8');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('does not match its published checksum', $io->errorOutput());
		$this->assertSame([], glob("{$this->cache}/9.9.8/*") ?: []);
	}

	public function testRejectsABuildThatDoesNotRun(): void
	{
		[$exit, $io] = $this->install('9.9.6');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('does not run on this system', $io->errorOutput());
		$this->assertSame([], glob("{$this->cache}/9.9.6/*") ?: []);
	}

	public function testReportsAnUnknownVersion(): void
	{
		[$exit, $io] = $this->install('1.0.0');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('FrankenPHP 1.0.0 was not found.', $io->errorOutput());
	}

	public function testExplainsAnExhaustedRateLimit(): void
	{
		[$exit, $io] = $this->install('4.0.3');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('rate limit is exhausted until', $io->errorOutput());
		$this->assertStringContainsString('GITHUB_TOKEN', $io->errorOutput());
	}

	public function testRejectsAnInvalidVersionWithoutARequest(): void
	{
		[$exit, $io] = $this->install('../9.9.9');

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid FrankenPHP version '../9.9.9'", $io->errorOutput());
		$this->assertSame([], $this->requests());
	}

	public function testAnInstalledVersionIsNotDownloadedAgain(): void
	{
		$this->install('9.9.9');
		$requests = count($this->requests());
		[$exit, $io] = $this->install('9.9.9');

		$this->assertSame(0, $exit);
		$this->assertStringContainsString('FrankenPHP 9.9.9 is already installed', $io->output());
		$this->assertCount($requests, $this->requests());
	}

	public function testInstallMentionsAFrankenPhpOnPathThatTakesPrecedence(): void
	{
		$this->fake("{$this->dir}/bin/frankenphp");
		[$exit, $io] = $this->install();

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString("uses {$this->dir}/bin/frankenphp from PATH", $io->output());
		$this->assertStringContainsString("version: '9.9.9'", $io->output());
	}

	public function testConfiguredExecutableComesFirst(): void
	{
		$this->fake("{$this->dir}/bin/frankenphp");
		$this->fake("{$this->dir}/custom");

		$this->assertSame("{$this->dir}/custom", $this->resolve("{$this->dir}/custom", null));
	}

	public function testPinnedVersionComesBeforePath(): void
	{
		$this->fake("{$this->dir}/bin/frankenphp");
		$this->fake("{$this->cache}/9.9.9/frankenphp");

		$this->assertSame("{$this->cache}/9.9.9/frankenphp", $this->resolve(null, 'v9.9.9'));
	}

	public function testPathComesBeforeTheCache(): void
	{
		$this->fake("{$this->dir}/bin/frankenphp");
		$this->fake("{$this->cache}/9.9.9/frankenphp");

		$this->assertSame("{$this->dir}/bin/frankenphp", $this->resolve(null, null));
	}

	public function testNewestCachedVersionComesLast(): void
	{
		$this->fake("{$this->cache}/1.9.0/frankenphp");
		$this->fake("{$this->cache}/1.10.0/frankenphp");
		$this->fake("{$this->cache}/latest/frankenphp");
		mkdir("{$this->cache}/2.0.0");

		$this->assertSame("{$this->cache}/1.10.0/frankenphp", $this->resolve(null, null));
	}

	/** @param ?string $version A pinned version, or none */
	#[DataProvider('missingVersions')]
	public function testMissingFrankenPhpIsOnlyDownloadedInATerminal(?string $version, string $message): void
	{
		$binary = FrankenBinary::resolve(null, $version, new BufferedIo(), interactive: false);

		$this->assertSame($message, $binary);
		$this->assertSame([], $this->requests());
	}

	public static function missingVersions(): array
	{
		return [
			'pinned' => ['9.9.9', 'FrankenPHP 9.9.9 is not installed. Run the command in a terminal to download it.'],
			'any' => [
				null,
				'FrankenPHP was not found. Put frankenphp on PATH, or run the command in a terminal to download it.',
			],
		];
	}

	/** @param ?string $version A pinned version, or none */
	#[DataProvider('downloads')]
	public function testMissingFrankenPhpIsDownloadedOnceConfirmed(?string $version, string $installed): void
	{
		$io = new BufferedIo("y\n");
		$binary = FrankenBinary::resolve(null, $version, $io, interactive: true);

		$this->assertInstanceOf(FrankenBinary::class, $binary, $io->errorOutput());
		$this->assertSame("{$this->cache}/{$installed}/frankenphp", $binary->path);
		$this->assertExecutable($binary->path);
		$this->assertMatchesRegularExpression(
			'#Download (it|the latest release) to ' . preg_quote($this->cache, '#') . '\? \[y/N\]#',
			$io->output(),
		);
	}

	public static function downloads(): array
	{
		return [
			'pinned' => ['9.9.7', '9.9.7'],
			'latest' => [null, '9.9.9'],
		];
	}

	public function testDeclinedDownloadPointsToTheInstallCommand(): void
	{
		$binary = FrankenBinary::resolve(null, null, new BufferedIo("n\n"), interactive: true);

		$this->assertIsString($binary);
		$this->assertStringContainsString('frankenphp:install', $binary);
		$this->assertSame([], $this->requests());
	}

	public function testCommandRejectsAnExecutableWithAVersion(): void
	{
		$io = new BufferedIo();
		$command = new FrankenPhp('/tmp/public', executable: 'frankenphp', version: '9.9.9');

		$this->assertSame(1, $command(new Args([]), $io));
		$this->assertStringContainsString('either a FrankenPHP executable or a version', $io->errorOutput());
	}

	public function testStartupNamesFrankenPhpAndWarnsAboutMissingExtensions(): void
	{
		$project = "{$this->dir}/project";
		mkdir($project);
		file_put_contents($project . '/composer.json', json_encode([
			'require' => ['php' => '>=8.5', 'ext-json' => '*', 'ext-foo' => '*'],
			'require-dev' => ['ext-Zend-OPcache' => '*'],
		]));
		$executable = "{$this->dir}/frankenphp";
		file_put_contents($executable, <<<'SH'
			#!/bin/sh
			case "$1" in
			version) echo 'FrankenPHP v9.9.9 PHP 8.5.0 Caddy v2.11.0 h1:abc' ;;
			php-cli) echo '["core","json","zend-opcache"]' ;;
			esac
			SH);
		chmod($executable, 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$cwd = (string) getcwd();
		chdir($project);

		try {
			$io = new BufferedIo();
			$exit = (new FrankenPhp($project, executable: $executable))(
				new Args(['--host=127.0.0.1', "--port={$port}", '--no-watch']),
				$io,
			);
		} finally {
			chdir($cwd);
		}

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertSame("Serving http://127.0.0.1:{$port} (FrankenPHP 9.9.9, PHP 8.5.0)\n", $io->output());
		$this->assertStringContainsString(
			'FrankenPHP lacks extensions the project requires: ext-foo.',
			$io->errorOutput(),
		);
	}

	public function testProbeListsExtensionsAsComposerNamesThem(): void
	{
		$output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/src/probe.php'));
		$this->assertIsString($output);
		$extensions = json_decode($output, true);

		$this->assertIsArray($extensions);
		$this->assertContains('core', $extensions);
		$this->assertContains('standard', $extensions);

		if (extension_loaded('Zend OPcache')) {
			$this->assertContains('zend-opcache', $extensions);
		}
	}

	/** @param list<string|null> $platform */
	#[DataProvider('platforms')]
	public function testAssetMatchesThePlatform(array $platform, ?string $asset): void
	{
		$this->assertSame($asset, FrankenReleases::asset((string) $platform[0], (string) $platform[1]));
	}

	public static function platforms(): array
	{
		return [
			'apple silicon' => [['Darwin', 'arm64'], 'frankenphp-mac-arm64'],
			'intel mac' => [['Darwin', 'x86_64'], 'frankenphp-mac-x86_64'],
			'linux' => [['Linux', 'x86_64'], 'frankenphp-linux-x86_64'],
			'linux arm' => [['Linux', 'aarch64'], 'frankenphp-linux-aarch64'],
			'windows' => [['Windows', 'AMD64'], null],
			'other' => [['BSD', 'amd64'], null],
		];
	}

	#[DataProvider('versions')]
	public function testVersionsAreNormalized(string $version, ?string $normalized): void
	{
		$this->assertSame($normalized, FrankenCache::version($version));
	}

	public static function versions(): array
	{
		return [
			'plain' => ['1.12.7', '1.12.7'],
			'tag' => ['v1.12.7', '1.12.7'],
			'pre-release' => ['v1.13.0-rc.1', '1.13.0-rc.1'],
			'partial' => ['1.12', null],
			'path' => ['../1.12.7', null],
			'trailing' => ["1.12.7\n", null],
			'name' => ['latest', null],
		];
	}

	/** @return array{int, BufferedIo} */
	private function install(?string $version = null): array
	{
		$io = new BufferedIo();
		$exit = (new FrankenInstall())(new Args($version === null ? [] : [$version]), $io);

		return [$exit, $io];
	}

	private function resolve(?string $executable, ?string $version): string
	{
		$binary = FrankenBinary::resolve($executable, $version, new BufferedIo(), interactive: false);
		$this->assertInstanceOf(FrankenBinary::class, $binary, is_string($binary) ? $binary : '');

		return $binary->path;
	}

	private function assertExecutable(string $file): void
	{
		$this->assertTrue(is_file($file) && is_executable($file), "{$file} is not executable.");
	}

	private function fake(string $file): void
	{
		if (!is_dir(dirname($file))) {
			mkdir(dirname($file), recursive: true);
		}

		file_put_contents($file, "#!/bin/sh\n");
		chmod($file, 0o755);
	}

	/** @return list<string> */
	private function requests(): array
	{
		$log = "{$this->dir}/requests.log";

		return is_file($log) ? (file($log, FILE_IGNORE_NEW_LINES) ?: []) : [];
	}

	/**
	 * Starts the stand-in for the release API. Version 9.9.9 is the latest
	 * release; 9.9.7 publishes no checksum, 9.9.8 a wrong one, and the
	 * build of 9.9.6 reports another version. 4.0.3 hits the rate limit.
	 */
	private function serve(): int
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$router = "{$this->dir}/router.php";
		file_put_contents($router, <<<'PHP'
			<?php
			$uri = $_SERVER['REQUEST_URI'];
			$token = ($_SERVER['HTTP_AUTHORIZATION'] ?? '') === 'Bearer secret' ? 'token' : '-';
			file_put_contents(__DIR__ . '/requests.log', "GET {$uri} {$token}\n", FILE_APPEND);
			$base = 'http://' . $_SERVER['HTTP_HOST'];
			$build = static fn(string $version): string => "#!/bin/sh\necho 'FrankenPHP v"
				. ($version === '9.9.6' ? '1.0.0' : $version) . " PHP 8.5.0 Caddy v2.11.0'\n";
			$release = static function (string $version) use ($base, $build): string {
				$assets = [];
				foreach (['mac-arm64', 'mac-x86_64', 'linux-x86_64', 'linux-aarch64'] as $platform) {
					$asset = [
						'name' => "frankenphp-{$platform}",
						'size' => strlen($build($version)),
						'browser_download_url' => "{$base}/download/v{$version}/frankenphp-{$platform}",
						'digest' => 'sha256:' . match ($version) {
							'9.9.8' => str_repeat('0', 64),
							default => hash('sha256', $build($version)),
						},
					];
					if ($version === '9.9.7') {
						unset($asset['digest']);
					}
					$assets[] = $asset;
				}
				return (string) json_encode(['tag_name' => "v{$version}", 'assets' => $assets]);
			};
			if (preg_match('#^/releases/(?:latest|tags/v(9\.9\.[6-9]))$#', $uri, $match) === 1) {
				echo $release($match[1] ?? '9.9.9');
			} elseif ($uri === '/releases/tags/v4.0.3') {
				http_response_code(403);
				header('x-ratelimit-remaining: 0');
				header('x-ratelimit-reset: 1893456000');
				echo '{"message": "API rate limit exceeded"}';
			} elseif (preg_match('#^/download/v([\d.]+)/#', $uri, $match) === 1) {
				header("Location: /asset/{$match[1]}", response_code: 302);
			} elseif (preg_match('#^/asset/([\d.]+)$#', $uri, $match) === 1) {
				echo $build($match[1]);
			} else {
				http_response_code(404);
			}
			PHP);
		$server = proc_open(
			[PHP_BINARY, '-S', "127.0.0.1:{$port}", $router],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
		);
		$this->assertIsResource($server);
		$this->server = $server;

		for ($i = 0; $i < 100 && Ports::unavailableMessage('127.0.0.1', $port) === null; $i++) {
			usleep(20_000);
		}

		return $port;
	}
}
