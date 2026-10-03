<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Server\ErrorTrap;
use Celema\Server\FrankenPhp;
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
	}

	public function testUnreadableSettingsFail(): void
	{
		if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
			$this->markTestSkipped('Root reads every file.');
		}

		file_put_contents("{$this->dir}/cserve.ini", "memory_limit=256M\n");
		chmod("{$this->dir}/cserve.ini", 0o000);

		$this->assertStringStartsWith('Failed to load cserve.ini', (string) ProjectIni::load($this->dir));
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
		// The package's settings revalidate on every request.
		file_put_contents("{$this->dir}/cserve.ini", "opcache.revalidate_freq=7\nmemory_limit=321M\n");
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
			$ini->remove();
		}

		$this->assertDirectoryDoesNotExist($ini->dir);
	}

	#[DataProvider('commands')]
	public function testCommandsServeWithTheProjectSettings(string $command): void
	{
		file_put_contents("{$this->dir}/cserve.ini", "memory_limit=321M\n");
		$executable = "{$this->dir}/backend";
		// Answers the version queries, and records the settings it was started with.
		file_put_contents(
			$executable,
			"#!/bin/sh\n"
				. "case \"\$1\" in -n|version|php-cli) exit 0;; esac\n"
				. "printf '%s' \"\$PHP_INI_SCAN_DIR\" > {$this->dir}/scan\n"
				. "cat \"\${PHP_INI_SCAN_DIR##*:}/cserve.ini\" > {$this->dir}/copy\n",
		);
		chmod($executable, 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$io = new BufferedIo();
			$backend = $command === 'server'
				? new Server('/tmp/public', executable: $executable)
				: new FrankenPhp('/tmp/public', executable: $executable);
			$exit = $backend(new Args(['--host=127.0.0.1', "--port={$port}", '--no-watch', '--quiet']), $io);
		} finally {
			chdir($cwd);
		}

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString("\nPHP settings: cserve.ini\n", $io->output());
		$this->assertSame("memory_limit=321M\n", file_get_contents("{$this->dir}/copy"));
		$scan = explode(PATH_SEPARATOR, (string) file_get_contents("{$this->dir}/scan"));
		$this->assertSame(dirname(__DIR__) . '/src/ini', $scan[count($scan) - 2]);
		$this->assertDirectoryDoesNotExist($scan[count($scan) - 1]);
	}

	public static function commands(): array
	{
		return [['server'], ['frankenphp']];
	}
}
