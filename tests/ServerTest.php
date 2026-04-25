<?php

declare(strict_types=1);

namespace Duon\Core\Tests;

use Duon\Core\Server\Options;
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
				'**/*.{php,js,css}',
				'--port',
				'1983',
				'--host',
				'localhost',
				'--no-ui',
				'--no-notify',
				'--no-open',
				'--reload-delay',
				'100',
				'--reload-debounce',
				'300',
			],
			$command,
		);
	}

	public function testBrowserSyncCommandAddsMultipleFileFlags(): void
	{
		$setup = new Setup(
			'/tmp/public',
			'',
			[
				'app/**/*.php',
				'vendor/duon/cms/**/*.{js,css,php}',
			],
		);
		$command = $setup->browserSyncCommand('localhost', 1983, 1984, false);

		$this->assertSame(
			[
				'npx',
				'browser-sync',
				'start',
				'--proxy',
				'http://localhost:1984',
				'--files',
				'app/**/*.php',
				'--files',
				'vendor/duon/cms/**/*.{js,css,php}',
				'--port',
				'1983',
				'--host',
				'localhost',
				'--no-ui',
				'--no-notify',
				'--no-open',
				'--reload-delay',
				'100',
				'--reload-debounce',
				'300',
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

	public function testWatchFlagUsesConfiguredPatternWithoutValue(): void
	{
		$this->withArgv(['run.php', 'server', '--watch'], function (): void {
			$options = Options::from(1983, ['**/*.php', '**/*.css']);
			$this->assertTrue($options->watch);
			$this->assertSame(['**/*.php', '**/*.css'], $options->watchFiles);
		});
	}

	public function testWatchFlagValueOverridesConfiguredPattern(): void
	{
		$this->withArgv(['run.php', 'server', '--watch', '**/*.twig'], function (): void {
			$options = Options::from(1983, ['**/*.php', '**/*.css']);
			$this->assertTrue($options->watch);
			$this->assertSame(['**/*.twig'], $options->watchFiles);
		});
	}

	public function testWatchFlagSupportsMultipleValues(): void
	{
		$this->withArgv([
			'run.php',
			'server',
			'--watch',
			'app/**/*.php',
			'--watch',
			'vendor/duon/cms/**/*.{js,css,php}',
		], function (): void {
			$options = Options::from(1983, Setup::DEFAULT_WATCH);
			$this->assertTrue($options->watch);
			$this->assertSame(
				[
					'app/**/*.php',
					'vendor/duon/cms/**/*.js',
					'vendor/duon/cms/**/*.css',
					'vendor/duon/cms/**/*.php',
				],
				array_slice($options->watchFiles, 0, 4),
			);
		});
	}

	public function testWatchPatternParsesBraceCommasCorrectly(): void
	{
		$this->withArgv(['run.php', 'server', '--watch'], function (): void {
			$options = Options::from(
				1983,
				'app/**/*.php, public/**/*.{js,php,css,jpg,png}, vendor/duon/cms/**/*.{js,css,php}',
			);
			$this->assertSame(
				[
					'app/**/*.php',
					'public/**/*.js',
					'public/**/*.php',
					'public/**/*.css',
					'public/**/*.jpg',
					'public/**/*.png',
					'vendor/duon/cms/**/*.js',
					'vendor/duon/cms/**/*.css',
					'vendor/duon/cms/**/*.php',
				],
				array_slice($options->watchFiles, 0, 9),
			);
		});
	}

	private function withArgv(array $argv, callable $callback): void
	{
		$oldArgv = $_SERVER['argv'] ?? null;
		$_SERVER['argv'] = $argv;

		try {
			$callback();
		} finally {
			if ($oldArgv === null) {
				unset($_SERVER['argv']);
			} else {
				$_SERVER['argv'] = $oldArgv;
			}
		}
	}
}
