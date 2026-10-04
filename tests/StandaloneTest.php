<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\Ports;
use Celema\Server\Standalone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StandaloneTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		$dir = sys_get_temp_dir() . '/celema-standalone-' . bin2hex(random_bytes(4));
		mkdir($dir);
		// The working directory of the binary is the real path.
		$this->dir = (string) realpath($dir);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	/**
	 * @param list<string> $argv
	 * @param list<string> $expected
	 */
	#[DataProvider('arguments')]
	public function testServerIsTheDefaultCommand(array $argv, array $expected): void
	{
		$this->assertSame($expected, Standalone::argv($argv));
	}

	public static function arguments(): array
	{
		return [
			'nothing' => [['cserve'], ['cserve', 'server']],
			'options' => [['cserve', '--port=8000', '-o'], ['cserve', 'server', '--port=8000', '-o']],
			'server' => [['cserve', 'frankenphp', '--worker'], ['cserve', 'server', 'frankenphp', '--worker']],
			'builtin' => [['cserve', 'builtin', '-o'], ['cserve', 'server', 'builtin', '-o']],
			'command' => [['cserve', 'reload', '-q'], ['cserve', 'reload', '-q']],
			'explicit server' => [['cserve', 'server', 'frankenphp'], ['cserve', 'server', 'frankenphp']],
			'help' => [['cserve', '--help'], ['cserve', 'help']],
			'short help' => [['cserve', '-h', 'reload'], ['cserve', 'help', 'reload']],
		];
	}

	/** @param list<string> $dirs */
	#[DataProvider('docroots')]
	public function testPublicDirectoryIsDetected(array $dirs, string $expected): void
	{
		foreach ($dirs as $dir => $front) {
			mkdir("{$this->dir}/{$dir}");

			if ($front) {
				file_put_contents("{$this->dir}/{$dir}/index.php", '<?php');
			}
		}

		$this->assertSame(rtrim("{$this->dir}/{$expected}", '/'), Standalone::docroot($this->dir));
	}

	public static function docroots(): array
	{
		return [
			'public' => [['public' => true, 'web' => true], 'public'],
			'web' => [['web' => true], 'web'],
			'htdocs' => [['public' => false, 'htdocs' => true], 'htdocs'],
			'none' => [['assets' => true], ''],
		];
	}

	public function testListsTheCommandsOfThePackage(): void
	{
		[$exit, $output] = $this->runBinary(['commands']);

		$this->assertSame(0, $exit);
		$this->assertSame("frankenphp:install\ninstall\nreload\nserver\n", $output);
	}

	public function testOptionsGoToTheServerCommand(): void
	{
		[$exit, $output] = $this->runBinary(['--port=foo']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid port 'foo'", $output);
	}

	public function testServesTheDetectedPublicDirectory(): void
	{
		mkdir("{$this->dir}/public");
		file_put_contents("{$this->dir}/public/index.php", '<?php');
		mkdir("{$this->dir}/bin");
		// Reports the arguments the server would run with.
		file_put_contents("{$this->dir}/bin/php", "#!/bin/sh\n[ \"\$1\" = -S ] && echo \"php \$*\" >&2\nexit 0\n");
		chmod("{$this->dir}/bin/php", 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);

		[$exit, $output] = $this->runBinary(['--host=127.0.0.1', "--port={$port}", '--no-watch'], "{$this->dir}/bin");

		$this->assertSame(0, $exit, $output);
		$this->assertStringContainsString("Public directory: public\n", $output);
		$this->assertStringContainsString("php -S 127.0.0.1:{$port} -t {$this->dir}/public ", $output);
	}

	public function testServesWithTheConfiguredServer(): void
	{
		mkdir("{$this->dir}/.cserve");
		file_put_contents("{$this->dir}/.cserve/config.ini", "server = frankenphp\n");
		mkdir("{$this->dir}/bin");
		// Reports how FrankenPHP would run, and answers its other queries.
		file_put_contents(
			"{$this->dir}/bin/frankenphp",
			"#!/bin/sh\n[ \"\$1\" = run ] && echo \"frankenphp \$1\" >&2\nexit 0\n",
		);
		chmod("{$this->dir}/bin/frankenphp", 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);

		[$exit, $output] = $this->runBinary(['--host=127.0.0.1', "--port={$port}", '--no-watch'], "{$this->dir}/bin");

		$this->assertSame(0, $exit, $output);
		$this->assertStringContainsString("frankenphp run\n", $output);
	}

	public function testInvalidConfigIsAnError(): void
	{
		mkdir("{$this->dir}/.cserve");
		file_put_contents("{$this->dir}/.cserve/config.ini", "server = caddy\n");

		[$exit, $output] = $this->runBinary([]);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid server 'caddy' in .cserve/config.ini", $output);
	}

	public function testWarnsWhenServingTheWholeDirectory(): void
	{
		[, $output] = $this->runBinary(['--port=foo']);

		$this->assertStringContainsString('serving the whole working directory', $output);
	}

	/**
	 * Runs the binary in the temporary directory.
	 *
	 * @param list<string> $args
	 * @return array{int, string}
	 */
	private function runBinary(array $args, ?string $path = null): array
	{
		$environment = [...getenv(), 'NO_COLOR' => '1'];

		if ($path !== null) {
			$environment['PATH'] = $path . PATH_SEPARATOR . '/usr/bin:/bin';
		}

		$output = "{$this->dir}/output";
		$stream = fopen($output, 'w');
		$process = proc_open(
			[PHP_BINARY, dirname(__DIR__) . '/bin/cserve', ...$args],
			[0 => ['file', '/dev/null', 'r'], 1 => $stream, 2 => $stream],
			$pipes,
			$this->dir,
			$environment,
		);
		$this->assertIsResource($process);
		$exit = proc_close($process);

		return [$exit, (string) file_get_contents($output)];
	}
}
