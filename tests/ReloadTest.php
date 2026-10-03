<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Server\Ports;
use Celema\Server\Reload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the reload command in its own process group, as it runs until it
 * is interrupted.
 */
final class ReloadTest extends TestCase
{
	private string $dir = '';

	/** @var list<resource> */
	private array $processes = [];

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/celema-reload-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/views", recursive: true);
		file_put_contents("{$this->dir}/views/page.php", 'before');
	}

	protected function tearDown(): void
	{
		foreach ($this->processes as $process) {
			proc_terminate($process, 9);
			proc_close($process);
		}

		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testPagesReloadAfterTheWorkersOfTheExternalServerRestarted(): void
	{
		if (!function_exists('posix_setsid')) {
			$this->markTestSkipped('Needs the posix extension.');
		}

		$admin = $this->adminApi();
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		[$command, $pid] = $this->start(["--port={$port}", "--admin=http://127.0.0.1:{$admin}"]);
		$this->waitFor(static fn(): bool => Ports::unavailableMessage('127.0.0.1', $port) !== null);

		$script = file_get_contents("http://127.0.0.1:{$port}/celema-live-reload.js");
		$this->assertIsString($script);
		$this->assertStringContainsString('EventSource', $script);

		$events = stream_socket_client("tcp://127.0.0.1:{$port}", timeout: 2);
		$this->assertIsResource($events);
		fwrite($events, "GET /events HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
		usleep(300_000);
		file_put_contents("{$this->dir}/views/page.php", 'after');
		$received = $this->read($events, 'event: morph');
		fclose($events);

		$this->assertStringContainsString('event: morph', $received);
		$this->assertSame(
			"POST /frankenphp/workers/restart 127.0.0.1:{$admin}\n",
			(string) file_get_contents(
				"{$this->dir}/admin.log",
			),
		);

		posix_kill(-$pid, SIGINT);
		$this->assertSame(130, $this->wait($command));
		$output = (string) file_get_contents("{$this->dir}/output");
		$this->assertStringContainsString(
			"CELEMA_LIVE_RELOAD=http://127.0.0.1:{$port}/celema-live-reload.js\n",
			$output,
		);
		$this->assertStringContainsString('restart worker', $output);
	}

	public function testUnreachableAdminApiIsReported(): void
	{
		$port = Ports::ephemeral();
		$admin = Ports::ephemeral();
		$this->assertIsInt($port);
		$this->assertIsInt($admin);
		[$command, $pid] = $this->start(["--port={$port}", "--admin=127.0.0.1:{$admin}"]);
		$this->waitFor(fn(): bool => str_contains(
			(string) file_get_contents("{$this->dir}/output"),
			'does not answer',
		));

		posix_kill(-$pid, SIGINT);
		$this->wait($command);
		$this->assertStringContainsString(
			"The FrankenPHP admin API at tcp://127.0.0.1:{$admin} does not answer",
			(string) file_get_contents("{$this->dir}/output"),
		);
	}

	public function testBusyPortIsAnError(): void
	{
		$server = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($server);
		$port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':') ?: '', 1);

		try {
			$io = new BufferedIo();
			$exit = (new Reload(watch: 'views/*.php'))(new Args(['--host=127.0.0.1', "--port={$port}"]), $io);
		} finally {
			fclose($server);
		}

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Port 127.0.0.1:{$port} is not available", $io->errorOutput());
	}

	#[DataProvider('invalidAdminAddresses')]
	public function testInvalidAdminAddressIsRejected(string $address): void
	{
		$io = new BufferedIo();
		$exit = (new Reload())(new Args(["--admin={$address}"]), $io);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid admin API address '{$address}'", $io->errorOutput());
	}

	public static function invalidAdminAddresses(): array
	{
		return [
			'https' => ['https://localhost:2019'],
			'no host' => ['http://:2019'],
			'other scheme' => ['ftp://localhost'],
		];
	}

	/**
	 * @param list<string> $args
	 * @return array{resource, int}
	 */
	private function start(array $args): array
	{
		$autoload = dirname(__DIR__) . '/vendor/autoload.php';
		file_put_contents("{$this->dir}/run", <<<PHP
			<?php
			require '{$autoload}';
			posix_setsid();
			\$command = new Celema\\Server\\Reload(watch: 'views/*.php');
			exit(new Celema\\Console\\Runner(new Celema\\Console\\Commands([\$command]))->run());
			PHP);
		$output = fopen("{$this->dir}/output", 'w');
		$command = proc_open(
			[PHP_BINARY, "{$this->dir}/run", 'reload', '--host=127.0.0.1', ...$args],
			[1 => $output, 2 => $output],
			$pipes,
			$this->dir,
			[...getenv(), 'NO_COLOR' => '1'],
		);
		$this->assertIsResource($command);

		return [$command, proc_get_status($command)['pid']];
	}

	/** Starts a stand-in for FrankenPHP's admin API that logs its requests. */
	private function adminApi(): int
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		file_put_contents("{$this->dir}/admin.php", <<<'PHP'
			<?php
			file_put_contents(
				__DIR__ . '/admin.log',
				"{$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']} {$_SERVER['HTTP_HOST']}\n",
				FILE_APPEND,
			);
			PHP);
		$process = proc_open(
			[PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$this->dir}/admin.php"],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
		);
		$this->assertIsResource($process);
		$this->processes[] = $process;
		$this->waitFor(static fn(): bool => Ports::unavailableMessage('127.0.0.1', $port) !== null);

		return $port;
	}

	/** @param resource $stream */
	private function read(mixed $stream, string $until): string
	{
		stream_set_blocking($stream, false);
		$received = '';

		for ($i = 0; $i < 100 && !str_contains($received, $until); $i++) {
			$read = [$stream];
			$write = null;
			$except = null;
			stream_select($read, $write, $except, 0, 50_000);
			$received .= (string) fread($stream, 8192);
		}

		return $received;
	}

	/** @param callable(): bool $condition */
	private function waitFor(callable $condition): void
	{
		for ($i = 0; $i < 100 && !$condition(); $i++) {
			usleep(50_000);
		}
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

		$this->processes[] = $command;
		$this->fail('The command did not stop.');
	}
}
