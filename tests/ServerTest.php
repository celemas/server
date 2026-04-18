<?php

declare(strict_types=1);

namespace Duon\Core\Tests;

use Closure;
use Duon\Core\Server\Server;
use InvalidArgumentException;

final class ServerTest extends TestCase
{
	public function testPhpCommandAddsQuietFlag(): void
	{
		$server = new Server('/tmp/public');
		$command = $this->invoke($server, 'phpCommand', 'localhost', 1983, true);

		$this->assertSame(
			[
				'php',
				'-S',
				'localhost:1983',
				'-q',
				'-t',
				'/tmp/public',
				dirname(__DIR__) . '/src/Server/CliRouter.php',
			],
			$command,
		);
	}

	public function testBrowserSyncCommandUsesProxyPort(): void
	{
		$server = new Server('/tmp/public');
		$command = $this->invoke($server, 'browserSyncCommand', 'localhost', 1983, 1984, false);

		$this->assertSame(
			[
				'npx',
				'browser-sync',
				'start',
				'--proxy',
				'http://localhost:1984',
				'--files',
				'**/*.php, **/*.css, **/*.js',
				'--port',
				'1983',
				'--host',
				'localhost',
				'--no-ui',
				'--no-notify',
			],
			$command,
		);
	}

	public function testPortRejectsInvalidValue(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid port 'foo'.");

		$server = new Server('/tmp/public');
		$this->invoke($server, 'port', 'foo');
	}

	public function testBrowserSyncNeedsBackendPort(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('BrowserSync needs a free backend port after the public port.');

		$server = new Server('/tmp/public');
		$this->invoke($server, 'browserSyncBackendPort', 65_535);
	}

	private function invoke(Server $server, string $method, mixed ...$args): mixed
	{
		$invoker = Closure::bind(
			static fn(Server $server, string $method, array $args): mixed => $server->{$method}(...$args),
			null,
			Server::class,
		);

		return $invoker($server, $method, $args);
	}
}
