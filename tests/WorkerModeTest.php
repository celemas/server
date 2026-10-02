<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Server\LiveReload;
use Celema\Server\Options;
use Celema\Server\Pending;
use Celema\Server\Ports;
use Celema\Server\Process;
use Celema\Server\Relay;
use Celema\Server\Setup;
use Celema\Server\WorkerRestart;
use PHPUnit\Framework\TestCase;

final class WorkerModeTest extends TestCase
{
	public function testWorkerCaddyfileRunsOneWorkerBehindALoopbackAdminApi(): void
	{
		$config = new Setup('/srv/site/public', '')->frankenPhpCaddyfile('localhost', 1983, false, 4321);

		$this->assertIsString($config);
		$this->assertStringContainsString("\tadmin \"127.0.0.1:4321\"\n", $config);
		$this->assertStringContainsString(
			"\tphp_server {\n\t\tworker {\n\t\t\tfile \"/srv/site/public/index.php\"\n\t\t\tnum 1\n\t\t}\n\t}\n",
			$config,
		);
		$this->assertStringNotContainsString('@prefix', $config);
	}

	public function testWorkerCaddyfileKeepsTheRoutePrefix(): void
	{
		$config = new Setup('/srv/site/public', '/site')->frankenPhpCaddyfile('localhost', 1983, false, 4321);

		$this->assertIsString($config);
		$this->assertStringContainsString('@prefix path "/site" "/site/*"', $config);
		$this->assertStringContainsString("\t\tphp_server {\n\t\t\tworker {\n", $config);
	}

	public function testAdminApiIsOffWithoutWorker(): void
	{
		$config = new Setup('/srv/site/public', '/site')->frankenPhpCaddyfile('localhost', 1983, false);

		$this->assertIsString($config);
		$this->assertStringContainsString("\tadmin off\n", $config);
		$this->assertStringContainsString("\t\tphp_server\n", $config);
	}

	public function testWorkerOptionImpliesWatching(): void
	{
		$options = Options::from(1983, Setup::DEFAULT_WATCH, new Args(['--worker']));

		$this->assertTrue($options->worker);
		$this->assertTrue($options->watch);
		$this->assertFalse(Options::from(1983, Setup::DEFAULT_WATCH, new Args([]))->worker);
	}

	public function testDefaultWatchPatternsIncludeSqlFiles(): void
	{
		$this->assertSame(['**/*.{php,js,css,sql,tpql}'], Setup::DEFAULT_WATCH);
	}

	public function testOnlyStylesheetsAndScriptsNeedNoRestart(): void
	{
		$this->assertFalse(WorkerRestart::needed(['public/app.css', 'public/app.JS']));
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
		$adminPort = Ports::ephemeral();
		$reloadPort = Ports::ephemeral();
		$this->assertIsInt($adminPort);
		$this->assertIsInt($reloadPort);
		$dir = sys_get_temp_dir() . '/celema-relay-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$page = "{$dir}/page.php";
		file_put_contents($page, 'content');
		file_put_contents("{$dir}/backend.php", <<<'PHP'
			<?php
			$server = stream_socket_server('tcp://127.0.0.1:' . $argv[1]);
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
		$events = [];
		$liveReload = LiveReload::listen(
			'127.0.0.1',
			$reloadPort,
			[$page],
			static function (string $event) use (&$events): void {
				$events[] = "reload {$event}";
			},
			static function () use ($adminPort, &$events): Pending {
				return WorkerRestart::send(
					$adminPort,
					static function (?string $error) use (&$events): void {
						$events[] = $error ?? 'restarted';
					},
					5,
				);
			},
		);
		$this->assertInstanceOf(LiveReload::class, $liveReload);
		$backend = Process::start([PHP_BINARY, "{$dir}/backend.php", (string) $adminPort]);
		$this->assertInstanceOf(Process::class, $backend);
		$lines = 0;

		try {
			$this->waitForPort($adminPort);
			file_put_contents($page, 'changed content');
			Relay::run([$backend->binding([2 => static function () use (&$lines): void {
				$lines++;
			}])], $liveReload);

			$this->assertSame(['restarted', 'reload reload'], $events);
			$this->assertSame(20_000, $lines);
		} finally {
			$backend->close(terminate: true);
			$liveReload->close();
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

			$this->assertSame(['before reload page', 'log reload'], $calls);
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
		for ($i = 0; $i < 100; $i++) {
			if (Ports::unavailableMessage('127.0.0.1', $port) !== null) {
				return;
			}

			usleep(20_000);
		}

		$this->fail("The admin API stand-in did not listen on port {$port}");
	}
}
