<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Server\ErrorTrap;
use Celema\Server\Ports;
use Celema\Server\Process;
use Celema\Server\ProjectIni;
use Celema\Server\Server;
use Celema\Server\Setup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectIniTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		$dir = sys_get_temp_dir() . '/celema-project-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$this->dir = (string) realpath($dir);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testProjectWithoutSettingsLoadsNothing(): void
	{
		$this->assertNull(ProjectIni::load($this->dir));

		// Only ini files count, like PHP only reads those.
		mkdir("{$this->dir}/.cserve/php", recursive: true);
		file_put_contents("{$this->dir}/.cserve/php/README.md", "Settings\n");

		$this->assertNull(ProjectIni::load($this->dir));
	}

	public function testSettingsAreTheIniFilesOfTheProjectDirectory(): void
	{
		mkdir("{$this->dir}/.cserve/php", recursive: true);
		file_put_contents("{$this->dir}/.cserve/php/xdebug.ini", "xdebug.mode=debug\n");
		file_put_contents("{$this->dir}/.cserve/php/local.ini", "memory_limit=321M\n");
		file_put_contents("{$this->dir}/.cserve/php/notes.txt", "Settings\n");

		$ini = ProjectIni::load($this->dir);

		$this->assertInstanceOf(ProjectIni::class, $ini);
		$this->assertSame("{$this->dir}/.cserve/php", $ini->dir);
		$this->assertSame(['local.ini', 'xdebug.ini'], $ini->files);
	}

	public function testEnvironmentScansTheProjectSettingsLast(): void
	{
		$inherited = getenv('PHP_INI_SCAN_DIR');

		try {
			putenv('PHP_INI_SCAN_DIR=/etc/php/conf.d');
			$setup = new Setup('/tmp/public', '');
			$expected =
				'/etc/php/conf.d' . PATH_SEPARATOR . dirname(__DIR__) . '/src/ini' . PATH_SEPARATOR . '/tmp/ini';

			$this->assertSame($expected, $setup->phpEnvironment(false, iniDir: '/tmp/ini')['PHP_INI_SCAN_DIR']);
			$this->assertSame($expected, $setup->frankenPhpEnvironment(iniDir: '/tmp/ini')['PHP_INI_SCAN_DIR']);
		} finally {
			putenv($inherited === false ? 'PHP_INI_SCAN_DIR' : "PHP_INI_SCAN_DIR={$inherited}");
		}
	}

	public function testProjectSettingsOverrideThePackageSettings(): void
	{
		// The package's settings revalidate on every request; later files win.
		mkdir("{$this->dir}/.cserve/php", recursive: true);
		file_put_contents("{$this->dir}/.cserve/php/a.ini", "opcache.revalidate_freq=7\nmemory_limit=123M\n");
		file_put_contents("{$this->dir}/.cserve/php/b.ini", "memory_limit=321M\n");
		file_put_contents(
			"{$this->dir}/index.php",
			"<?php echo ini_get('opcache.revalidate_freq'), ' ', ini_get('memory_limit');",
		);
		$ini = ProjectIni::load($this->dir);
		$this->assertInstanceOf(ProjectIni::class, $ini);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$setup = new Setup($this->dir, '', php: PHP_BINARY);
		$server = Process::start(
			$setup->phpCommand('127.0.0.1', $port, true),
			$setup->phpEnvironment(false, iniDir: $ini->dir),
		);
		$this->assertInstanceOf(Process::class, $server);

		try {
			$response = false;

			for ($i = 0; $i < 50 && $response === false; $i++) {
				usleep(100_000);
				$response = ErrorTrap::run(static fn(): mixed => file_get_contents("http://127.0.0.1:{$port}/"));
			}

			$this->assertSame('7 321M', $response);
		} finally {
			$server->close(terminate: true);
		}
	}

	#[DataProvider('commands')]
	public function testCommandsServeWithTheProjectSettings(string $command): void
	{
		mkdir("{$this->dir}/.cserve/php", recursive: true);
		file_put_contents("{$this->dir}/.cserve/php/local.ini", "memory_limit=321M\n");
		$executable = "{$this->dir}/backend";
		// Answers the version queries, and records the settings it was started with.
		file_put_contents(
			$executable,
			"#!/bin/sh\n"
				. "case \"\$1\" in -n|version|php-cli) exit 0;; esac\n"
				. "printf '%s' \"\$PHP_INI_SCAN_DIR\" > {$this->dir}/scan\n",
		);
		chmod($executable, 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$io = new BufferedIo();
			$backend = $command === 'builtin'
				? new Server('/tmp/public', php: $executable)
				: new Server('/tmp/public', server: 'frankenphp', frankenphp: $executable);
			$exit = $backend(new Args(['--host=127.0.0.1', "--port={$port}", '--no-watch', '--quiet']), $io);
		} finally {
			chdir($cwd);
		}

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString("\nPHP settings: .cserve/php/local.ini\n", $io->output());
		$scan = explode(PATH_SEPARATOR, (string) file_get_contents("{$this->dir}/scan"));
		$this->assertSame([dirname(__DIR__) . '/src/ini', "{$this->dir}/.cserve/php"], array_slice($scan, -2));
	}

	public function testFrankenPhpProbesExtensionsWithProjectSettings(): void
	{
		mkdir("{$this->dir}/.cserve/php", recursive: true);
		file_put_contents("{$this->dir}/.cserve/php/extensions.ini", "user_agent=project-extension\n");
		file_put_contents("{$this->dir}/composer.json", json_encode([
			'require' => ['ext-project-extension' => '*', 'ext-missing' => '*'],
		]));
		$executable = "{$this->dir}/backend";
		// Model an extension enabled by project settings without requiring
		// a loadable extension on the test machine. PHP still reads the ini.
		$probe = escapeshellarg("echo json_encode([ini_get('user_agent')]);");
		$php = escapeshellarg(PHP_BINARY);
		file_put_contents($executable, "#!/bin/sh\ncase \"\$1\" in php-cli) exec {$php} -r {$probe};; esac\n");
		chmod($executable, 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$io = new BufferedIo();
			$exit = (new Server($this->dir, server: 'frankenphp', frankenphp: $executable))(
				new Args(['--host=127.0.0.1', "--port={$port}", '--no-watch']),
				$io,
			);
		} finally {
			chdir($cwd);
		}

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString(
			'FrankenPHP lacks extensions the project requires: ext-missing.',
			$io->errorOutput(),
		);
	}

	public static function commands(): array
	{
		return [['builtin'], ['frankenphp']];
	}
}
