<?php

declare(strict_types=1);

namespace Celemas\Core\Tests;

use Celemas\Core\Server\Console;
use Celemas\Core\Server\Options;
use Celemas\Core\Server\Setup;
use InvalidArgumentException;
use RuntimeException;

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
				'vendor/celemas/cms/**/*.{js,css,php}',
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
				'vendor/celemas/cms/**/*.{js,css,php}',
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
			'vendor/celemas/cms/**/*.{js,css,php}',
		], function (): void {
			$options = Options::from(1983, Setup::DEFAULT_WATCH);
			$this->assertTrue($options->watch);
			$this->assertSame(
				[
					'app/**/*.php',
					'vendor/celemas/cms/**/*.js',
					'vendor/celemas/cms/**/*.css',
					'vendor/celemas/cms/**/*.php',
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
				'app/**/*.php, public/**/*.{js,php,css,jpg,png}, vendor/celemas/cms/**/*.{js,css,php}',
			);
			$this->assertSame(
				[
					'app/**/*.php',
					'public/**/*.js',
					'public/**/*.php',
					'public/**/*.css',
					'public/**/*.jpg',
					'public/**/*.png',
					'vendor/celemas/cms/**/*.js',
					'vendor/celemas/cms/**/*.css',
					'vendor/celemas/cms/**/*.php',
				],
				array_slice($options->watchFiles, 0, 9),
			);
		});
	}

	public function testConsoleFlushesHandledException(): void
	{
		$this->withCliServer(function (): void {
			$this->withErrorLogFile(function (string $file): void {
				Console::recordException(new RuntimeException('Boom'), trace: false);

				$this->assertTrue(Console::hasException());

				Console::flushException();
				$log = file_get_contents($file);

				$this->assertFalse(Console::hasException());
				$this->assertIsString($log);
				$this->assertStringContainsString(RuntimeException::class . ': Boom', $log);
				$this->assertStringContainsString('in ', $log);
				$this->assertStringNotContainsString('Trace:', $log);
			});
		});
	}

	public function testConsoleIgnoresExceptionOutsideDevServer(): void
	{
		$this->withCliServer(function (): void {
			Console::recordException(new RuntimeException('Boom'), trace: true);
			$this->assertTrue(Console::hasException());
		});
		$oldValue = $_SERVER['CELEMAS_CLI_SERVER'] ?? null;
		$_SERVER['CELEMAS_CLI_SERVER'] = '0';

		try {
			Console::recordException(new RuntimeException('Ignored'), trace: true);

			$this->assertFalse(Console::hasException());
		} finally {
			if ($oldValue === null) {
				unset($_SERVER['CELEMAS_CLI_SERVER']);
			} else {
				$_SERVER['CELEMAS_CLI_SERVER'] = $oldValue;
			}
		}
	}

	/** @param callable(): void $callback */
	private function withCliServer(callable $callback): void
	{
		$oldValue = $_SERVER['CELEMAS_CLI_SERVER'] ?? null;
		$_SERVER['CELEMAS_CLI_SERVER'] = '1';

		try {
			$callback();
		} finally {
			Console::clearException();

			if ($oldValue === null) {
				unset($_SERVER['CELEMAS_CLI_SERVER']);
			} else {
				$_SERVER['CELEMAS_CLI_SERVER'] = $oldValue;
			}
		}
	}

	/** @param callable(string): void $callback */
	private function withErrorLogFile(callable $callback): void
	{
		$previous = ini_get('error_log');
		$file = tempnam(sys_get_temp_dir(), 'core-error-log-');

		if ($file === false) {
			$this->fail('Could not create temporary error log file.');
		}

		// @mago-expect lint:no-ini-set
		ini_set('error_log', $file);

		try {
			$callback($file);
		} finally {
			if ($previous === false) {
				ini_restore('error_log');
			} else {
				// @mago-expect lint:no-ini-set
				ini_set('error_log', $previous);
			}

			if (is_file($file)) {
				unlink($file);
			}
		}
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
