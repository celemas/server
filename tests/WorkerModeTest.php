<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Console\Commands;
use Celema\Console\Runner;
use Celema\Server\ErrorTrap;
use Celema\Server\LiveReload;
use Celema\Server\Options;
use Celema\Server\Pending;
use Celema\Server\Ports;
use Celema\Server\Process;
use Celema\Server\Relay;
use Celema\Server\Server;
use Celema\Server\Setup;
use Celema\Server\WorkerRestart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkerModeTest extends TestCase
{
	public function testWorkerCaddyfileRunsOneWorkerBehindALoopbackAdminApi(): void
	{
		$config = new Setup('/srv/site/public', '')->frankenPhpCaddyfile('localhost', 1983, false, 4321, 1);

		$this->assertStringContainsString("\tadmin \"127.0.0.1:4321\"\n", $config);
		$this->assertStringContainsString(
			"\tphp_server {\n\t\tworker {\n\t\t\tfile \"/srv/site/public/index.php\"\n\t\t\tnum 1\n\t\t}\n\t}\n",
			$config,
		);
		$this->assertStringNotContainsString('@prefix', $config);
	}

	public function testWorkerCaddyfileKeepsTheRoutePrefix(): void
	{
		$config = new Setup('/srv/site/public', '/site')->frankenPhpCaddyfile('localhost', 1983, false, 4321, 1);

		$this->assertStringContainsString('@prefix path "/site" "/site/*"', $config);
		$this->assertStringContainsString("\t\tphp_server {\n\t\t\tworker {\n", $config);
	}

	public function testAdminApiIsOffWithoutWorker(): void
	{
		$config = new Setup('/srv/site/public', '/site')->frankenPhpCaddyfile('localhost', 1983, false);

		$this->assertStringContainsString("\tadmin off\n", $config);
		$this->assertStringContainsString("\t\tphp_server\n", $config);
	}

	/** @param list<string> $args */
	#[DataProvider('workerOptions')]
	public function testWorkerCountAndWatchingAreIndependent(array $args, ?int $workers, bool $watch): void
	{
		$options = Options::from(1983, Setup::DEFAULT_WATCH, new Args($args));

		$this->assertSame($workers, $options->workers);
		$this->assertSame($watch, $options->watch);
	}

	public static function workerOptions(): array
	{
		return [
			'classic' => [[], null, true],
			'default' => [['--worker'], 1, true],
			'explicit one' => [['--worker=1'], 1, true],
			'multiple' => [['--worker=8'], 8, true],
			'maximum integer' => [['--worker=' . PHP_INT_MAX], PHP_INT_MAX, true],
			'classic without watching' => [['--no-watch'], null, false],
			'default without watching' => [['--worker', '--no-watch'], 1, false],
			'multiple without watching' => [['--worker=8', '--no-watch'], 8, false],
		];
	}

	#[DataProvider('invalidWorkerCounts')]
	public function testInvalidWorkerCountFailsBeforeStartup(string $count): void
	{
		$io = new BufferedIo();
		$exit = (new Server('/tmp/public', server: 'frankenphp', frankenphp: '__missing_frankenphp_binary__'))(
			new Args(["--worker={$count}"]),
			$io,
		);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('must be a positive integer', $io->errorOutput());
	}

	public static function invalidWorkerCounts(): array
	{
		return [
			'empty' => [''],
			'zero' => ['0'],
			'negative' => ['-1'],
			'fraction' => ['1.5'],
			'text' => ['eight'],
			'exponent' => ['1e2'],
			'sign' => ['+8'],
			'whitespace' => [' 8'],
			'newline' => ["8\n"],
			'overflow' => [(string) PHP_INT_MAX . '0'],
		];
	}

	#[DataProvider('workerCommands')]
	public function testCommandPassesWorkerCountToBackend(string $option, int $count, string $prefix, bool $watch): void
	{
		$dir = sys_get_temp_dir() . '/celema-worker-command-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$executable = "{$dir}/frankenphp";
		$config = "{$dir}/Caddyfile";
		// Capture the configuration while it exists; the backend then exits.
		file_put_contents($executable, '#!/bin/sh' . "\ncp \"\$3\" " . escapeshellarg($config) . "\n");
		chmod($executable, 0o755);
		$argv = $_SERVER['argv'];

		try {
			$port = Ports::ephemeral();
			$this->assertIsInt($port);
			$_SERVER['argv'] = ['run', 'server', 'frankenphp', $option, '--host=127.0.0.1', "--port={$port}"];

			if (!$watch) {
				$_SERVER['argv'][] = '--no-watch';
			}

			$io = new BufferedIo();
			$command = new Server(
				$dir,
				server: 'frankenphp',
				routePrefix: $prefix,
				watch: [$executable],
				frankenphp: $executable,
			);

			$this->assertSame(0, new Runner(new Commands([$command]), $io)->run(), $io->errorOutput());
			$contents = file_get_contents($config);
			$this->assertIsString($contents);
			$this->assertStringContainsString("num {$count}\n", $contents);
			$this->assertStringContainsString('file "' . $dir . '/index.php"', $contents);
			if ($watch) {
				$this->assertStringContainsString('admin "127.0.0.1:', $contents);
				$this->assertStringContainsString('Live reload script:', $io->output());
			} else {
				$this->assertStringContainsString("\tadmin off\n", $contents);
				$this->assertMatchesRegularExpression('#^Serving http://127\.0\.0\.1:\d+\n$#', $io->output());
			}
		} finally {
			$_SERVER['argv'] = $argv;
			array_map(unlink(...), glob("{$dir}/*") ?: []);
			rmdir($dir);
		}
	}

	public static function workerCommands(): array
	{
		return [
			'default' => ['--worker', 1, '', true],
			'multiple' => ['--worker=8', 8, '', true],
			'prefixed default' => ['--worker', 1, '/site', true],
			'prefixed multiple' => ['--worker=8', 8, '/site', true],
			'default without watching' => ['--worker', 1, '', false],
			'multiple without watching' => ['--worker=8', 8, '', false],
			'prefixed without watching' => ['--worker=8', 8, '/site', false],
		];
	}

	public function testDefaultWatchPatternsIncludeSqlFiles(): void
	{
		$this->assertSame(['**/*.{php,js,css,sql,tpql}'], Setup::DEFAULT_WATCH);
	}

	public function testOnlyStylesheetsAndScriptsNeedNoRestart(): void
	{
		$this->assertFalse(WorkerRestart::needed(['public/app.css', 'public/app.JS', 'public/app.mjs']));
		$this->assertTrue(WorkerRestart::needed(['public/app.css', 'src/Page.php']));
		$this->assertTrue(WorkerRestart::needed(['db/sql/nodes/find.sql']));
		$this->assertTrue(WorkerRestart::needed(['lang/de.php']));
	}

	public function testRestartPostsToTheAdminApi(): void
	{
		$this->withAdminApi(200, function (int $port, string $log): void {
			$this->assertNull($this->restart($port));
			$this->assertSame("POST /frankenphp/workers/restart\n", (string) file_get_contents($log));
		});
	}

	public function testFailedRestartIsReported(): void
	{
		$this->withAdminApi(500, function (int $port): void {
			$this->assertStringContainsString('500 Internal Server Error answered', (string) $this->restart($port));
		});
	}

	public function testUnreachableAdminApiIsReported(): void
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);

		$this->assertStringStartsWith('Could not restart the worker', (string) $this->restart($port, 1));
	}

	public function testRestartWithoutAnswerTimesOut(): void
	{
		// Accepts connections into its backlog but never answers.
		$server = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($server);
		$port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':') ?: '', 1);

		try {
			$this->assertStringContainsString('no answer from the FrankenPHP admin API within 1 s', (string) $this->restart(
				$port,
				1,
			));
		} finally {
			fclose($server);
		}
	}

	/**
	 * FrankenPHP logs while it restarts. The relay reads that output while
	 * the restart is pending, or a full pipe blocks the backend before it
	 * can answer.
	 */
	public function testBackendOutputIsRelayedWhileTheRestartIsPending(): void
	{
		$reloadPort = Ports::ephemeral();
		$this->assertIsInt($reloadPort);
		$dir = sys_get_temp_dir() . '/celema-relay-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$page = "{$dir}/page.php";
		file_put_contents($page, 'content');
		// The admin API stand-in picks its own port and reports it once it
		// listens, so no other process can take the port in between.
		file_put_contents("{$dir}/backend.php", <<<'PHP'
			<?php
			$server = stream_socket_server('tcp://127.0.0.1:0');
			$name = (string) stream_socket_get_name($server, false);
			file_put_contents("{$argv[1]}.tmp", substr($name, strrpos($name, ':') + 1));
			rename("{$argv[1]}.tmp", $argv[1]);
			$client = stream_socket_accept($server, 10);
			$request = '';

			while (!str_contains($request, "\r\n\r\n") && !feof($client)) {
				$request .= fread($client, 1024);
			}

			// More than a pipe holds, written before the answer.
			for ($i = 0; $i < 20_000; $i++) {
				fwrite(STDERR, str_repeat('x', 99) . "\n");
			}

			fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
			fclose($client);
			// Keeps running until the relay read the answer.
			usleep(500_000);
			PHP);
		$backend = Process::start([PHP_BINARY, "{$dir}/backend.php", "{$dir}/port"]);
		$this->assertInstanceOf(Process::class, $backend);
		$events = [];
		$lines = 0;
		$liveReload = null;

		try {
			$adminPort = $this->reportedPort("{$dir}/port");
			$liveReload = LiveReload::listen(
				'127.0.0.1',
				$reloadPort,
				[$page],
				static function (string $event) use (&$events): void {
					$events[] = "reload {$event}";
				},
				static function () use ($adminPort, &$events): Pending {
					return WorkerRestart::send(
						'127.0.0.1',
						$adminPort,
						static function (?string $error) use (&$events): void {
							$events[] = $error ?? 'restarted';
						},
						5,
					);
				},
			);
			$this->assertInstanceOf(LiveReload::class, $liveReload);
			file_put_contents($page, 'changed content');
			Relay::run([$backend->binding([2 => static function () use (&$lines): void {
				$lines++;
			}])], $liveReload);

			$this->assertSame(['restarted', 'reload morph'], $events);
			$this->assertSame(20_000, $lines);
		} finally {
			$backend->close(terminate: true);

			if ($liveReload instanceof LiveReload) {
				$liveReload->close();
			}

			array_map(unlink(...), glob("{$dir}/*") ?: []);
			rmdir($dir);
		}
	}

	public function testPagesReloadOnceThePendingWorkIsFinished(): void
	{
		$dir = sys_get_temp_dir() . '/celema-pending-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$file = "{$dir}/page.php";
		file_put_contents($file, 'content');
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$pending = new class implements Pending {
			public bool $finished = false;

			public function streams(): array
			{
				return [];
			}

			public function advance(): bool
			{
				return $this->finished;
			}
		};
		$reloads = 0;
		$liveReload = LiveReload::listen(
			'127.0.0.1',
			$port,
			[$file],
			static function () use (&$reloads): void {
				$reloads++;
			},
			static fn(): Pending => $pending,
		);
		$this->assertInstanceOf(LiveReload::class, $liveReload);

		try {
			file_put_contents($file, 'changed content');
			$this->poll($liveReload);
			$this->poll($liveReload);
			$this->poll($liveReload);
			$waiting = $reloads;
			$pending->finished = true;
			$liveReload->poll();

			$this->assertSame(0, $waiting);
			$this->assertSame(1, $reloads);
		} finally {
			$liveReload->close();
			unlink($file);
			rmdir($dir);
		}
	}

	public function testEphemeralPortIsFree(): void
	{
		$port = Ports::ephemeral();

		$this->assertIsInt($port);
		$this->assertNull(Ports::unavailableMessage('127.0.0.1', $port));
	}

	public function testEphemeralPortReportsWhyBindingFailed(): void
	{
		// A documentation address, never assigned to this machine.
		$message = Ports::ephemeral('192.0.2.1');

		$this->assertIsString($message);
		$this->assertMatchesRegularExpression('/^No free port on 192\.0\.2\.1: \S/', $message);
	}

	public function testBeforeReloadRunsBeforePagesAreNotified(): void
	{
		$dir = sys_get_temp_dir() . '/celema-worker-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$file = "{$dir}/page.php";
		file_put_contents($file, 'content');
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$calls = [];
		$liveReload = LiveReload::listen(
			'127.0.0.1',
			$port,
			[$file],
			static function (string $event) use (&$calls): void {
				$calls[] = "log {$event}";
			},
			static function (string $event, array $files) use (&$calls, $file): ?Pending {
				$calls[] = "before {$event} " . ($files === [$file] ? 'page' : 'other');

				return null;
			},
		);
		$this->assertInstanceOf(LiveReload::class, $liveReload);

		try {
			file_put_contents($file, 'changed content');
			$this->poll($liveReload);
			$this->poll($liveReload);

			$this->assertSame(['before morph page', 'log morph'], $calls);
		} finally {
			$liveReload->close();
			unlink($file);
			rmdir($dir);
		}
	}

	/** Advances a restart as the relay does, until it is finished, and returns its outcome. */
	private function restart(int $port, int $timeout = 10): ?string
	{
		$outcome = 'unfinished';
		$restart = WorkerRestart::send(
			'127.0.0.1',
			$port,
			static function (?string $error) use (&$outcome): void {
				$outcome = $error;
			},
			$timeout,
		);

		for ($i = 0; $i < 400 && !$restart->advance(); $i++) {
			$read = $restart->streams();
			$write = null;
			$except = null;
			stream_select($read, $write, $except, 0, 50_000);
		}

		return $outcome;
	}

	private function poll(LiveReload $liveReload): void
	{
		// Scans run at most every 200 ms.
		usleep(210_000);
		clearstatcache();
		$liveReload->poll();
	}

	/** Runs a stand-in for the admin API that answers with the given status. */
	private function withAdminApi(int $status, callable $callback): void
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$dir = sys_get_temp_dir() . '/celema-admin-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$log = "{$dir}/requests.log";
		file_put_contents("{$dir}/router.php", <<<PHP
			<?php
			file_put_contents('{$log}', \$_SERVER['REQUEST_METHOD'] . ' ' . \$_SERVER['REQUEST_URI'] . "\\n", FILE_APPEND);
			http_response_code({$status});
			echo 'answered';
			PHP);
		$process = proc_open(
			[PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$dir}/router.php"],
			[1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$pipes,
		);
		$this->assertIsResource($process);

		try {
			$this->waitForPort($port);
			$callback($port, $log);
		} finally {
			proc_terminate($process);
			proc_close($process);
			array_map(unlink(...), glob("{$dir}/*") ?: []);
			rmdir($dir);
		}
	}

	private function waitForPort(int $port): void
	{
		for ($i = 0; $i < 250; $i++) {
			// Unlike binding the port, connecting cannot keep the server from binding it.
			$client = ErrorTrap::run(static fn(): mixed => stream_socket_client(
				"tcp://127.0.0.1:{$port}",
				timeout: 0.1,
			));

			if (is_resource($client)) {
				fclose($client);

				return;
			}

			usleep(20_000);
		}

		$this->fail("The admin API stand-in did not listen on port {$port}");
	}

	/** Waits for the port a stand-in reports in the given file once it listens. */
	private function reportedPort(string $file): int
	{
		for ($i = 0; $i < 250 && !is_file($file); $i++) {
			usleep(20_000);
		}

		$this->assertFileExists($file, 'The admin API stand-in did not report its port.');

		return (int) file_get_contents($file);
	}
}
