<?php

declare(strict_types=1);

namespace Duon\Core\Tests;

use Duon\Core\Server\Setup;
use InvalidArgumentException;

final class ServerTest extends TestCase
{
	public function testPhpCommandAddsQuietFlag(): void
	{
		$setup = new Setup('/tmp/public', '');
		$command = $setup->phpCommand('localhost', 1983, true);

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
		$setup = new Setup('/tmp/public', '');
		$command = $setup->browserSyncCommand('localhost', 1983, 1984, false);

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
				'--no-open',
			],
			$command,
		);
	}

	public function testPortRejectsInvalidValue(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid port 'foo'.");

		Setup::port('foo');
	}

	public function testBrowserSyncNeedsBackendPort(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('BrowserSync needs a free backend port after the public port.');

		Setup::backendPort(65_535);
	}
}
