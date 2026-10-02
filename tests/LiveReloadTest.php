<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\LiveReload;
use Celema\Server\ReloadEndpoint;
use Celema\Server\ReloadResponse;
use PHPUnit\Framework\TestCase;

final class LiveReloadTest extends TestCase
{
	public function testScriptUrlUsesAReachableHost(): void
	{
		$this->assertSame('http://localhost:1983/celema-live-reload.js', ReloadResponse::url('localhost', 1983));
		$this->assertSame('http://localhost:1983/celema-live-reload.js', ReloadResponse::url('0.0.0.0', 1983));
		$this->assertSame('http://[::1]:1983/celema-live-reload.js', ReloadResponse::url('::1', 1983));
	}

	public function testServesTheScript(): void
	{
		[$endpoint, $port] = $this->endpoint();

		try {
			$response = $this->request($endpoint, $port, '/celema-live-reload.js');

			$this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
			$this->assertStringContainsString('Content-Type: text/javascript', $response);
			$this->assertStringContainsString('new EventSource(', $response);
		} finally {
			$endpoint->close();
		}
	}

	public function testServesIdiomorph(): void
	{
		[$endpoint, $port] = $this->endpoint();

		try {
			$response = $this->request($endpoint, $port, '/idiomorph.js');
			$module = (string) file_get_contents(dirname(__DIR__) . '/src/idiomorph/idiomorph.esm.js');

			$this->assertStringStartsWith("HTTP/1.1 200 OK\r\n", $response);
			$this->assertStringContainsString('Content-Type: text/javascript', $response);
			$this->assertStringEndsWith("\r\n\r\n" . $module, $response, 'Serves the whole module.');
		} finally {
			$endpoint->close();
		}
	}

	public function testLocalhostListensOnBothLoopbackAddresses(): void
	{
		$port = $this->freePort();
		$endpoint = ReloadEndpoint::listen('localhost', $port);
		$this->assertInstanceOf(ReloadEndpoint::class, $endpoint);

		try {
			$this->assertStringStartsWith(
				"HTTP/1.1 200 OK\r\n",
				$this->request($endpoint, $port, '/celema-live-reload.js'),
			);

			if (count($endpoint->streams()) < 2) {
				$this->markTestSkipped('No IPv6 loopback on this machine.');
			}

			$this->assertStringStartsWith(
				"HTTP/1.1 200 OK\r\n",
				$this->request($endpoint, $port, '/celema-live-reload.js', '[::1]'),
			);
		} finally {
			$endpoint->close();
		}
	}

	public function testRejectsUnknownPaths(): void
	{
		[$endpoint, $port] = $this->endpoint();

		try {
			$this->assertStringStartsWith(
				"HTTP/1.1 404 Not Found\r\n",
				$this->request($endpoint, $port, '/unknown'),
			);
		} finally {
			$endpoint->close();
		}
	}

	public function testBroadcastsEventsToConnectedPages(): void
	{
		[$endpoint, $port] = $this->endpoint();

		try {
			$first = $this->subscribe($endpoint, $port);
			$second = $this->subscribe($endpoint, $port);

			$this->assertSame(2, $endpoint->broadcast('reload'));
			$this->assertStringContainsString("event: reload\n", $this->receive($first));

			fclose($second);
			$this->pump($endpoint);

			$this->assertSame(1, $endpoint->broadcast('css'));
			$this->assertStringContainsString("event: css\n", $this->receive($first));
			fclose($first);
		} finally {
			$endpoint->close();
		}
	}

	public function testUpdatesPagesOnceChangesSettle(): void
	{
		$dir = sys_get_temp_dir() . '/celema-reload-' . bin2hex(random_bytes(4));
		mkdir($dir);
		$file = "{$dir}/page.php";
		$css = "{$dir}/app.css";
		$js = "{$dir}/app.js";
		file_put_contents($file, 'content');
		file_put_contents($css, 'body {}');
		file_put_contents($js, '');
		$port = $this->freePort();
		$log = [];
		$liveReload = LiveReload::listen(
			'127.0.0.1',
			$port,
			[$file, $css, $js],
			static function (string $event, array $files, int $pages) use (&$log): void {
				$log[] = [$event, $files, $pages];
			},
		);
		$this->assertInstanceOf(LiveReload::class, $liveReload);

		try {
			$this->assertSame("http://127.0.0.1:{$port}/celema-live-reload.js", $liveReload->script);
			$page = $this->subscribe($liveReload, $port);

			file_put_contents($css, 'body { color: red }');
			$this->poll($liveReload);
			$this->assertSame([], $log, 'Waits for a scan without further changes.');
			$this->poll($liveReload);

			$this->assertSame([['css', [$css], 1]], $log);
			$this->assertStringContainsString("event: css\ndata: \n\n", $this->receive($page));

			file_put_contents($file, 'changed content');
			$this->poll($liveReload);
			$this->poll($liveReload);

			$this->assertSame(['morph', [$file], 1], $log[1] ?? null);
			$this->assertStringContainsString("event: morph\ndata: \n\n", $this->receive($page));

			file_put_contents($file, 'content');
			file_put_contents($css, 'body { color: blue }');
			$this->poll($liveReload);
			$this->poll($liveReload);

			$this->assertSame('morph', $log[2][0] ?? null);
			$this->assertStringContainsString(
				"event: morph\ndata: css\n\n",
				$this->receive($page),
				'Changed stylesheets are swapped after the morph.',
			);

			file_put_contents($file, 'changed content');
			file_put_contents($js, 'console.log(1);');
			$this->poll($liveReload);
			$this->poll($liveReload);

			$this->assertSame('reload', $log[3][0] ?? null);
			$this->assertStringContainsString("event: reload\n", $this->receive($page));
			fclose($page);
		} finally {
			$liveReload->close();
			unlink($file);
			unlink($css);
			unlink($js);
			rmdir($dir);
		}
	}

	public function testReportsAnOccupiedPort(): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = (string) stream_socket_get_name($socket, false);
		$port = (int) substr($address, (int) strrpos($address, ':') + 1);

		try {
			$result = ReloadEndpoint::listen('127.0.0.1', $port);

			$this->assertIsString($result);
			$this->assertStringStartsWith("Failed to start live reload on 127.0.0.1:{$port}: ", $result);
		} finally {
			fclose($socket);
		}
	}

	/** @return array{ReloadEndpoint, int} */
	private function endpoint(): array
	{
		$port = $this->freePort();
		$endpoint = ReloadEndpoint::listen('127.0.0.1', $port);
		$this->assertInstanceOf(ReloadEndpoint::class, $endpoint);

		return [$endpoint, $port];
	}

	private function request(
		ReloadEndpoint $endpoint,
		int $port,
		string $path,
		string $address = '127.0.0.1',
	): string {
		$client = $this->connect($port, "GET {$path} HTTP/1.1\r\nHost: localhost\r\n\r\n", $address);
		$this->pump($endpoint);
		$response = (string) stream_get_contents($client);
		fclose($client);

		return $response;
	}

	/** @return resource */
	private function subscribe(ReloadEndpoint|LiveReload $endpoint, int $port): mixed
	{
		$client = $this->connect($port, "GET /events HTTP/1.1\r\nHost: localhost\r\n\r\n");
		$this->pump($endpoint);
		$this->assertStringContainsString('Content-Type: text/event-stream', $this->receive($client));

		return $client;
	}

	/** @return resource */
	private function connect(int $port, string $request, string $address = '127.0.0.1'): mixed
	{
		$client = stream_socket_client("tcp://{$address}:{$port}", timeout: 1);
		$this->assertIsResource($client);
		stream_set_timeout($client, 1);
		fwrite($client, $request);

		return $client;
	}

	/** @param resource $client */
	private function receive(mixed $client): string
	{
		$read = [$client];
		$write = null;
		$except = null;
		stream_select($read, $write, $except, 1);

		return (string) fread($client, 8192);
	}

	private function pump(ReloadEndpoint|LiveReload $endpoint): void
	{
		for ($i = 0; $i < 5; $i++) {
			$read = $endpoint->streams();
			$write = null;
			$except = null;

			if (stream_select($read, $write, $except, 0, 20_000) > 0) {
				$endpoint->handle($read);
			}
		}
	}

	/** Polls once the interval has passed, so the call actually scans. */
	private function poll(LiveReload $liveReload): void
	{
		usleep(210_000);
		$liveReload->poll();
	}

	private function freePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = (string) stream_socket_get_name($socket, false);
		fclose($socket);

		return (int) substr($address, (int) strrpos($address, ':') + 1);
	}
}
