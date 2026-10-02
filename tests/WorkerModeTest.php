<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Server\LiveReload;
use Celema\Server\Options;
use Celema\Server\Ports;
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
			$this->assertNull((new WorkerRestart($port))());
			$this->assertSame("POST /frankenphp/workers/restart\n", (string) file_get_contents($log));
		});
	}

	public function testFailedRestartIsReported(): void
	{
		$this->withAdminApi(500, function (int $port): void {
			$this->assertStringContainsString('500', (string) (new WorkerRestart($port))());
		});
	}

	public function testUnreachableAdminApiIsReported(): void
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);

		$this->assertStringStartsWith('Could not restart the worker', (string) (new WorkerRestart($port, 1))());
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
			static function (string $event, array $files) use (&$calls, $file): void {
				$calls[] = "before {$event} " . ($files === [$file] ? 'page' : 'other');
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
