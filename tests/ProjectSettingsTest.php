<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Server\Ports;
use Celema\Server\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How the project's settings and the command line combine into the options the server runs with. */
final class ProjectSettingsTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		$dir = sys_get_temp_dir() . '/celema-settings-' . bin2hex(random_bytes(4));
		mkdir("{$dir}/.cserve", recursive: true);
		$this->dir = (string) realpath($dir);

		// Both backends answer the version queries and report how they would
		// serve; FrankenPHP keeps its configuration, which is removed after.
		foreach (['php', 'frankenphp', 'other-php', 'other-frankenphp'] as $name) {
			file_put_contents(
				"{$this->dir}/{$name}",
				"#!/bin/sh\ncase \"\$1\" in -n|version|php-cli) exit 0;; esac\n"
					. "[ \"\$1\" = run ] && cp \"\$3\" {$this->dir}/Caddyfile\n"
					. "echo \"{$name} \$* debug=\${XDEBUG_SESSION-unset} workers=\${PHP_CLI_SERVER_WORKERS-unset}"
					. " script=\${CELEMA_LIVE_RELOAD-unset} prefix=\${CELEMA_ROUTE_PREFIX-unset}\" >&2\n",
			);
			chmod("{$this->dir}/{$name}", 0o755);
		}
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testSettingsReachTheServer(): void
	{
		$port = $this->port();
		$this->configure("host = 127.0.0.1\nport = {$port}\ndebug = true\nprocesses = 3\nwatch = false\n");

		[$exit, $buffer] = $this->serve([]);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString("php -S 127.0.0.1:{$port} ", $buffer->output());
		$this->assertStringContainsString('debug=1 workers=3 script=unset', $buffer->output());
	}

	public function testCommandLineOverridesTheSettings(): void
	{
		$port = $this->port();
		$this->configure("host = 127.0.0.1\nport = 1\ndebug = true\nprocesses = 3\nwatch = false\n");
		file_put_contents("{$this->dir}/index.php", '<?php');

		[$exit, $buffer] = $this->serve(["--port={$port}", '--no-debug', '--processes=2', '--watch']);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString("php -S 127.0.0.1:{$port} ", $buffer->output());
		$this->assertMatchesRegularExpression(
			'#debug=unset workers=2 script=http://127\.0\.0\.1:\d+/#',
			$buffer->output(),
		);
	}

	public function testWatchFilesReplaceTheRunScriptPatterns(): void
	{
		$this->configure("host = 127.0.0.1\nport = {$this->port()}\nwatch-files = 'src/*.php'\n");
		mkdir("{$this->dir}/src");
		file_put_contents("{$this->dir}/src/app.php", '<?php');

		[$exit, $buffer] = $this->serve([]);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString('Watching 1 file', $buffer->output());
	}

	/** @param list<string> $args */
	#[DataProvider('otherServers')]
	public function testSettingsOfTheOtherServerAreIgnored(string $settings, array $args, string $expected): void
	{
		$this->configure("host = 127.0.0.1\nport = {$this->port()}\nwatch = false\n{$settings}");

		[$exit, $buffer] = $this->serve($args);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString($expected, $buffer->output());
	}

	public static function otherServers(): array
	{
		return [
			'worker with builtin' => ["worker = 4\n", ['builtin'], 'php -S'],
			'processes with frankenphp' => ["processes = 4\n", ['frankenphp'], 'frankenphp run'],
		];
	}

	/** @param list<string> $args */
	#[DataProvider('workerSettings')]
	public function testWorkerSettingIsOverriddenByZero(string $local, array $args, string $expected): void
	{
		$this->configure("server = frankenphp\nhost = 127.0.0.1\nport = {$this->port()}\nworker = 4\n");
		file_put_contents("{$this->dir}/.cserve/config.local.ini", $local);

		[$exit, $buffer] = $this->serve($args);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString($expected, (string) file_get_contents("{$this->dir}/Caddyfile"));
	}

	public static function workerSettings(): array
	{
		return [
			'project setting' => ['', [], "\t\t\tnum 4\n"],
			'local setting' => ["worker = false\n", [], "\tphp_server\n"],
			'local count' => ["worker = 0\n", [], "\tphp_server\n"],
			'command line' => ['', ['--worker=0'], "\tphp_server\n"],
		];
	}

	public function testSettingsReplaceTheRunScriptArguments(): void
	{
		$this->configure(
			"host = 127.0.0.1\nport = {$this->port()}\nwatch = false\nphp = '{$this->dir}/other-php'\nroute-prefix = /site\n",
		);

		[$exit, $buffer] = $this->serve([]);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString('other-php -S', $buffer->output());
		$this->assertStringContainsString('prefix=/site', $buffer->output());
	}

	public function testExecutableSettingReplacesTheRunScriptVersion(): void
	{
		$this->configure(
			"server = frankenphp\nhost = 127.0.0.1\nport = {$this->port()}\nwatch = false\n"
				. "frankenphp = '{$this->dir}/other-frankenphp'\n",
		);

		[$exit, $buffer] = $this->serve([], new Server($this->dir, version: '9.9.9'));

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString('other-frankenphp run', $buffer->output());
	}

	public function testInvalidValueIsAnError(): void
	{
		$this->configure("port = 70000\n");

		[$exit, $buffer] = $this->serve([]);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Port '70000' must be between 1 and 65535.", $buffer->errorOutput());
	}

	private function configure(string $settings): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", $settings);
	}

	private function port(): int
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);

		return $port;
	}

	/**
	 * Runs the command in the project directory, by default with the stand-in
	 * backends.
	 *
	 * @param list<string> $args
	 * @return array{int, Buffer}
	 */
	private function serve(array $args, ?Server $command = null): array
	{
		$command ??= new Server($this->dir, php: "{$this->dir}/php", frankenphp: "{$this->dir}/frankenphp");
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$buffer = new Buffer();
			$exit = Cli::run($command, new Io($buffer), $args);
		} finally {
			chdir($cwd);
		}

		return [$exit, $buffer];
	}
}
