<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\Ports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the frankenphp command in its own process group against a backend
 * that waits until it is stopped, then interrupts the command.
 */
final class InterruptTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		if (!function_exists('pcntl_signal') || !function_exists('posix_setsid')) {
			$this->markTestSkipped('Needs the pcntl and posix extensions.');
		}

		$this->dir = sys_get_temp_dir() . '/celema-interrupt-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		if ($this->dir !== '') {
			array_map(unlink(...), glob("{$this->dir}/*") ?: []);
			rmdir($this->dir);
		}
	}

	/** @param list<string> $args */
	#[DataProvider('interruptions')]
	public function testInterruptStopsTheBackendAndRemovesTheConfiguration(
		bool $group,
		int $signal,
		array $args,
	): void {
		[$command, $pid] = $this->start($args);
		$backend = $this->backend();
		$config = (string) file_get_contents("{$this->dir}/config-path");
		$this->assertFileExists($config);

		// Ctrl+C in a terminal signals the whole process group; a kill
		// command signals the command alone.
		posix_kill($group ? -$pid : $pid, $signal);
		$exitCode = $this->wait($command);

		$this->assertSame(128 + $signal, $exitCode);
		$this->assertFileDoesNotExist($config);
		$this->assertFalse(posix_kill($backend, 0), 'The backend still runs.');
	}

	public static function interruptions(): array
	{
		return [
			'ctrl+c' => [true, SIGINT, ['--no-watch']],
			'ctrl+c while watching' => [true, SIGINT, []],
			'sigint to the command' => [false, SIGINT, ['--no-watch']],
			'sigterm to the command' => [false, SIGTERM, []],
		];
	}

	public function testStoppingThePhpServerStopsAllItsProcesses(): void
	{
		file_put_contents("{$this->dir}/index.php", '<?php echo "served";');
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		[$command, $pid] = $this->start(['--processes=2', '--no-watch'], 'builtin', $port);

		for ($i = 0; $i < 100 && Ports::unavailableMessage('127.0.0.1', $port) === null; $i++) {
			usleep(50_000);
		}

		$this->assertSame('served', file_get_contents("http://127.0.0.1:{$port}/"));
		posix_kill($pid, SIGTERM);

		$this->assertSame(143, $this->wait($command));
		// The forked server processes would keep the port.
		$this->assertNull(Ports::unavailableMessage('127.0.0.1', $port));
	}

	/**
	 * @param list<string> $args
	 * @return array{resource, int}
	 */
	private function start(array $args, string $name = 'frankenphp', ?int $port = null): array
	{
		$dir = $this->dir;
		file_put_contents(
			"{$dir}/frankenphp",
			"#!/bin/sh\n[ \"\$1\" = run ] || exit 0\nprintf '%s' \"\$3\" > config-path\necho \$\$ > backend.pid\nexec sleep 30\n",
		);
		chmod("{$dir}/frankenphp", 0o755);

		if (!is_file("{$dir}/index.php")) {
			file_put_contents("{$dir}/index.php", '<?php');
		}

		$autoload = dirname(__DIR__) . '/vendor/autoload.php';
		$php = PHP_BINARY;
		file_put_contents("{$dir}/run", <<<PHP
			<?php
			require '{$autoload}';
			posix_setsid();
			\$commands = [
				new Celema\\Server\\Server('{$dir}', watch: '*.php', php: '{$php}', frankenphp: '{$dir}/frankenphp'),
			];
			exit(new Celema\\Console\\Runner(\$commands)->run());
			PHP);
		$port ??= Ports::ephemeral();
		$this->assertIsInt($port);
		$command = proc_open(
			[PHP_BINARY, "{$dir}/run", 'server', $name, '--host=127.0.0.1', "--port={$port}", ...$args],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$dir}/stderr", 'w']],
			$pipes,
			$dir,
		);
		$this->assertIsResource($command);

		return [$command, proc_get_status($command)['pid']];
	}

	private function backend(): int
	{
		for ($i = 0; $i < 100 && !is_file("{$this->dir}/backend.pid"); $i++) {
			usleep(50_000);
		}

		$this->assertFileExists("{$this->dir}/backend.pid", (string) file_get_contents("{$this->dir}/stderr"));
		usleep(50_000);

		return (int) file_get_contents("{$this->dir}/backend.pid");
	}

	/** @param resource $command */
	private function wait(mixed $command): int
	{
		for ($i = 0; $i < 100; $i++) {
			$status = proc_get_status($command);

			if (!$status['running']) {
				proc_close($command);

				return $status['exitcode'];
			}

			usleep(50_000);
		}

		proc_terminate($command, SIGKILL);
		proc_close($command);
		$this->fail('The command did not stop.');
	}
}
