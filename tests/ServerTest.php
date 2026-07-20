<?php

declare(strict_types=1);

namespace Celema\Core\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Core\Server\Console;
use Celema\Core\Server\FrankenOptions;
use Celema\Core\Server\FrankenPhp;
use Celema\Core\Server\Options;
use Celema\Core\Server\Server;
use Celema\Core\Server\Setup;
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

	public function testFrankenPhpCommandUsesConfiguredServer(): void
	{
		$setup = new Setup('/tmp/public', '');

		$this->assertSame(
			[
				'frankenphp',
				'php-server',
				'--root',
				'/tmp/public',
				'--listen',
				'localhost:1983',
				'--access-log',
				'--debug',
			],
			$setup->frankenPhpCommand('localhost', 1983, true),
		);
	}

	public function testFrankenPhpCommandUsesCaddyfile(): void
	{
		$setup = new Setup('/tmp/public', '/prefix');

		$this->assertSame(
			[
				'frankenphp',
				'run',
				'--config',
				'/tmp/Caddyfile',
				'--adapter',
				'caddyfile',
			],
			$setup->frankenPhpCommand('localhost', 1983, true, '/tmp/Caddyfile'),
		);
	}

	public function testFrankenPhpCaddyfileRoutesPrefix(): void
	{
		$setup = new Setup('/tmp/public', '/prefix/');
		$config = $setup->frankenPhpCaddyfile('localhost', 1983, true);

		$this->assertIsString($config);
		$this->assertStringContainsString("\tdebug\n", $config);
		$this->assertStringContainsString('"http://localhost:1983"', $config);
		$this->assertStringContainsString('root * "/tmp/public"', $config);
		$this->assertStringContainsString('@prefix path "/prefix" "/prefix/*"', $config);
		$this->assertNull(new Setup('/tmp/public', '')->frankenPhpCaddyfile('localhost', 1983, false));
	}

	public function testFrankenPhpEnvironmentIdentifiesServer(): void
	{
		$environment = new Setup('/tmp/public', '/prefix')->frankenPhpEnvironment();

		$this->assertSame('frankenphp', $environment['CELEMA_CLI_SERVER']);
		$this->assertSame('/tmp/public', $environment['CELEMA_DOCUMENT_ROOT']);
		$this->assertSame('/prefix', $environment['CELEMA_ROUTE_PREFIX']);
	}

	public function testMissingFrankenPhpIsReported(): void
	{
		$setup = new Setup('/tmp/public', '', frankenPhp: '__missing_frankenphp_binary__');

		$this->assertTrue($setup->missingFrankenPhp());
	}

	public function testFrankenPhpCommandReportsMissingExecutable(): void
	{
		$io = new BufferedIo();
		$exit = (new FrankenPhp('/tmp/public', executable: '__missing_frankenphp_binary__'))(
			new Args([]),
			$io,
		);

		$this->assertSame(1, $exit);
		$this->assertSame('', $io->output());
		$this->assertStringContainsString(
			'FrankenPHP requires frankenphp in PATH.',
			$io->errorOutput(),
		);
	}

	public function testFrankenPhpCommandRejectsInvalidOptions(): void
	{
		$io = new BufferedIo();
		$exit = (new FrankenPhp('/tmp/public'))(new Args(['--port=foo']), $io);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid port 'foo'.", $io->errorOutput());
	}

	public function testFrankenPhpCommandRunsConfiguredExecutable(): void
	{
		$executable = tempnam(sys_get_temp_dir(), 'fake-frankenphp-');

		if ($executable === false) {
			$this->fail('Could not create a fake FrankenPHP executable.');
		}

		file_put_contents(
			$executable,
			"#!/bin/sh\nprintf '%s\\n' '{\"level\":\"info\",\"ts\":1784570344.75,"
			. '"logger":"http.log.access","msg":"handled request",'
			. '"request":{"method":"GET","uri":"/test","headers":{}},'
			. "\"duration\":0.001,\"status\":200}' >&2\n",
		);
		chmod($executable, 0o755);
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$this->assertIsString($address);
		$port = (int) substr($address, (int) strrpos($address, ':') + 1);

		try {
			$io = new BufferedIo();
			$exit = (new FrankenPhp('/tmp/public', routePrefix: '/prefix', executable: $executable))(
				new Args(['--host=127.0.0.1', "--port={$port}"]),
				$io,
			);

			$this->assertSame(0, $exit);
			$this->assertStringContainsString('200 GET /test', $io->output());
		} finally {
			unlink($executable);
		}
	}

	public function testFrankenOptionsUseCommandArguments(): void
	{
		$options = FrankenOptions::from(
			1983,
			['**/*.php'],
			new Args([
				'--host=127.0.0.1',
				'--port=8080',
				'--filter=#health#',
				'--debug',
				'--quiet',
				'--watch=**/*.twig',
			]),
		);

		$this->assertSame('127.0.0.1', $options->host);
		$this->assertSame(8080, $options->port);
		$this->assertSame('#health#', $options->filter);
		$this->assertTrue($options->debug);
		$this->assertTrue($options->quiet);
		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.twig'], $options->watchFiles);
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
				'vendor/celema/cms/**/*.{js,css,php}',
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
				'vendor/celema/cms/**/*.{js,css,php}',
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

	public function testInvalidOptionsReportToStderrAndFail(): void
	{
		$io = new BufferedIo();
		$exit = (new Server('/tmp/public'))(new Args(['--port=foo']), $io);

		$this->assertSame(1, $exit);
		$this->assertSame('', $io->output());
		$this->assertStringContainsString("Invalid port 'foo'.", $io->errorOutput());
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
		$options = Options::from(1983, ['**/*.php', '**/*.css'], new Args(['--watch']));

		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.php', '**/*.css'], $options->watchFiles);
	}

	public function testWatchFlagValueOverridesConfiguredPattern(): void
	{
		$options = Options::from(
			1983,
			['**/*.php', '**/*.css'],
			new Args(['--watch=**/*.twig']),
		);

		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.twig'], $options->watchFiles);
	}

	public function testWatchFlagSupportsMultipleValues(): void
	{
		$options = Options::from(
			1983,
			Setup::DEFAULT_WATCH,
			new Args([
				'--watch=app/**/*.php',
				'--watch=vendor/celema/cms/**/*.{js,css,php}',
			]),
		);

		$this->assertTrue($options->watch);
		$this->assertSame(
			[
				'app/**/*.php',
				'vendor/celema/cms/**/*.js',
				'vendor/celema/cms/**/*.css',
				'vendor/celema/cms/**/*.php',
			],
			array_slice($options->watchFiles, 0, 4),
		);
	}

	public function testWatchPatternParsesBraceCommasCorrectly(): void
	{
		$options = Options::from(
			1983,
			'app/**/*.php, public/**/*.{js,php,css,jpg,png}, vendor/celema/cms/**/*.{js,css,php}',
			new Args(['--watch']),
		);

		$this->assertSame(
			[
				'app/**/*.php',
				'public/**/*.js',
				'public/**/*.php',
				'public/**/*.css',
				'public/**/*.jpg',
				'public/**/*.png',
				'vendor/celema/cms/**/*.js',
				'vendor/celema/cms/**/*.css',
				'vendor/celema/cms/**/*.php',
			],
			array_slice($options->watchFiles, 0, 9),
		);
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

	public function testConsoleReportsFrankenPhpExceptionImmediately(): void
	{
		$this->withServer('frankenphp', function (): void {
			$this->withErrorLogFile(function (string $file): void {
				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_SERVER['REQUEST_URI'] = '/api?x=1';

				try {
					Console::recordException(new RuntimeException('Boom'), trace: false);
				} finally {
					unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
				}

				$log = file_get_contents($file);
				$this->assertFalse(Console::hasException());
				$this->assertIsString($log);
				$this->assertStringContainsString(
					'celema-exception {"method":"POST","uri":"/api?x=1"}',
					$log,
				);
				$this->assertStringContainsString(RuntimeException::class . ': Boom', $log);
			});
		});
	}

	public function testConsoleIgnoresExceptionOutsideDevServer(): void
	{
		$this->withCliServer(function (): void {
			Console::recordException(new RuntimeException('Boom'), trace: true);
			$this->assertTrue(Console::hasException());
		});
		$oldValue = $_SERVER['CELEMA_CLI_SERVER'] ?? null;
		$_SERVER['CELEMA_CLI_SERVER'] = '0';

		try {
			Console::recordException(new RuntimeException('Ignored'), trace: true);

			$this->assertFalse(Console::hasException());
		} finally {
			if ($oldValue === null) {
				unset($_SERVER['CELEMA_CLI_SERVER']);
			} else {
				$_SERVER['CELEMA_CLI_SERVER'] = $oldValue;
			}
		}
	}

	/** @param callable(): void $callback */
	private function withCliServer(callable $callback): void
	{
		$this->withServer('1', $callback);
	}

	/** @param callable(): void $callback */
	private function withServer(string $server, callable $callback): void
	{
		$oldValue = $_SERVER['CELEMA_CLI_SERVER'] ?? null;
		$_SERVER['CELEMA_CLI_SERVER'] = $server;

		try {
			$callback();
		} finally {
			Console::clearException();

			if ($oldValue === null) {
				unset($_SERVER['CELEMA_CLI_SERVER']);
			} else {
				$_SERVER['CELEMA_CLI_SERVER'] = $oldValue;
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
}
