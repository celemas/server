<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Console\Commands;
use Celema\Console\Runner;
use Celema\Server\Address;
use Celema\Server\Browser;
use Celema\Server\Console;
use Celema\Server\ErrorTrap;
use Celema\Server\FrankenPhp;
use Celema\Server\Options;
use Celema\Server\Ports;
use Celema\Server\Process;
use Celema\Server\Server;
use Celema\Server\Setup;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
				dirname(__DIR__) . '/src/CliRouter.php',
			],
			$command,
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
			$setup->frankenPhpCommand('/tmp/Caddyfile'),
		);
	}

	public function testFrankenPhpCaddyfileRoutesPrefix(): void
	{
		$setup = new Setup('/tmp/public', '/prefix/');
		$config = $setup->frankenPhpCaddyfile('localhost', 1983, true);

		$this->assertStringContainsString("\tdebug\n", $config);
		$this->assertStringContainsString('root * "/tmp/public"', $config);
		$this->assertStringContainsString('@prefix path "/prefix" "/prefix/*"', $config);
	}

	public function testFrankenPhpCaddyfileServesClassicModeLikePhpServer(): void
	{
		$config = new Setup('/tmp/public', '')->frankenPhpCaddyfile('localhost', 1983, false);

		$this->assertStringContainsString("\tadmin off\n", $config);
		$this->assertStringContainsString("\troot * \"/tmp/public\"\n\tencode zstd gzip\n\tphp_server\n", $config);
		$this->assertStringNotContainsString('@prefix', $config);
		$this->assertStringNotContainsString('debug', $config);
	}

	public function testFrankenPhpCaddyfileBindsToTheHostForEveryHostHeader(): void
	{
		$config = new Setup('/tmp/public', '/prefix')->frankenPhpCaddyfile('127.0.0.1', 1983, false);

		$this->assertStringContainsString("\n\"http://:1983\" {\n\tbind \"127.0.0.1\"\n", $config);
	}

	public function testFrankenPhpEnvironmentIdentifiesServer(): void
	{
		$environment = new Setup('/tmp/public', '/prefix')->frankenPhpEnvironment();

		$this->assertSame('frankenphp', $environment['CELEMA_CLI_SERVER']);
		$this->assertSame('/tmp/public', $environment['CELEMA_DOCUMENT_ROOT']);
		$this->assertSame('/prefix', $environment['CELEMA_ROUTE_PREFIX']);
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
			"The FrankenPHP executable '__missing_frankenphp_binary__' was not found.",
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

		$marker =
			'{"level":"info","logger":"frankenphp",'
			. '"msg":"celema-exception {\\"method\\":\\"GET\\",\\"uri\\":\\"/test\\",'
			. '\\"lines\\":[\\"RuntimeException: Boom\\"]}"}';
		file_put_contents(
			$executable,
			"#!/bin/sh\nprintf '%s\\n' "
				. escapeshellarg($marker)
				. " >&2\nprintf '%s\\n' '{\"level\":\"info\",\"ts\":1784570344.75,"
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
			$output = $io->output();
			$request = strpos($output, '200 GET /test');
			$exception = strpos($output, 'RuntimeException: Boom');
			$this->assertIsInt($request);
			$this->assertIsInt($exception);
			$this->assertLessThan($exception, $request);
		} finally {
			unlink($executable);
		}
	}

	public function testServerCommandRunsConfiguredExecutable(): void
	{
		$executable = tempnam(sys_get_temp_dir(), 'fake-php-');

		if ($executable === false) {
			$this->fail('Could not create a fake PHP executable.');
		}

		file_put_contents(
			$executable,
			"#!/bin/sh\nprintf '%s\\n' "
				. "'[Sun Jul 20 17:12:05 2026] celema-request 200 GET 0.00016 -- /test' >&2\n",
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
			$exit = (new Server('/tmp/public', executable: $executable))(
				new Args(['--host=127.0.0.1', "--port={$port}"]),
				$io,
			);

			$this->assertSame(0, $exit);
			$this->assertStringContainsString('200 GET /test', $io->output());
		} finally {
			unlink($executable);
		}
	}

	#[DataProvider('serverCommands')]
	public function testServerCommandsUseTheDefaultPort(string $command): void
	{
		$message = Ports::unavailableMessage('127.0.0.1', 2130);

		if ($message !== null) {
			$this->markTestSkipped($message);
		}

		$executable = tempnam(sys_get_temp_dir(), 'fake-backend-');
		$this->assertIsString($executable);
		file_put_contents($executable, "#!/bin/sh\nexit 0\n");
		chmod($executable, 0o755);

		try {
			$io = new BufferedIo();
			$backend = $command === 'server'
				? new Server('/tmp/public', executable: $executable)
				: new FrankenPhp('/tmp/public', executable: $executable);
			$exit = $backend(
				new Args(['--host=127.0.0.1', '--no-watch']),
				$io,
			);

			$this->assertSame(0, $exit, $io->errorOutput());
			$this->assertSame("Serving http://127.0.0.1:2130\n", $io->output());
		} finally {
			unlink($executable);
		}
	}

	public function testServerAnnouncesItsAddressAndPhpVersion(): void
	{
		$executable = tempnam(sys_get_temp_dir(), 'fake-php-');
		$this->assertIsString($executable);
		// Answers the version query, and serves nothing otherwise.
		file_put_contents($executable, "#!/bin/sh\n[ \"\$1\" = -n ] && printf '8.5.0'\nexit 0\n");
		chmod($executable, 0o755);
		$port = $this->freePort();

		try {
			$io = new BufferedIo();
			$exit = (new Server('/tmp/public', executable: $executable))(
				new Args(['--host=127.0.0.1', "--port={$port}", '--no-watch', '--quiet']),
				$io,
			);

			$this->assertSame(0, $exit);
			$this->assertSame("Serving http://127.0.0.1:{$port} (PHP 8.5.0)\n", $io->output());
		} finally {
			unlink($executable);
		}
	}

	public function testOptionsUseCommandArguments(): void
	{
		$options = Options::from(
			1983,
			['**/*.php'],
			new Args([
				'--host=127.0.0.1',
				'--port=8080',
				'--filter=#health#',
				'--debug',
				'--quiet',
				'--watch-files=**/*.twig',
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

	public function testEnvironmentPassesLiveReloadScript(): void
	{
		$setup = new Setup('/tmp/public', '');
		$script = 'http://localhost:19830/celema-live-reload.js';

		$this->assertSame($script, $setup->phpEnvironment(false, $script)['CELEMA_LIVE_RELOAD']);
		$this->assertSame($script, $setup->frankenPhpEnvironment($script)['CELEMA_LIVE_RELOAD']);
	}

	public function testEnvironmentAddsTheServerIniSettings(): void
	{
		$dir = dirname(__DIR__) . '/src/ini';
		$inherited = getenv('PHP_INI_SCAN_DIR');

		try {
			putenv('PHP_INI_SCAN_DIR');
			$setup = new Setup('/tmp/public', '');

			$this->assertSame(PATH_SEPARATOR . $dir, $setup->phpEnvironment(false)['PHP_INI_SCAN_DIR']);
			$this->assertSame(PATH_SEPARATOR . $dir, $setup->frankenPhpEnvironment()['PHP_INI_SCAN_DIR']);

			putenv('PHP_INI_SCAN_DIR=/etc/php/conf.d');
			$this->assertSame(
				'/etc/php/conf.d' . PATH_SEPARATOR . $dir,
				$setup->phpEnvironment(false)['PHP_INI_SCAN_DIR'],
			);

			putenv('PHP_INI_SCAN_DIR=');
			$this->assertSame($dir, $setup->phpEnvironment(false)['PHP_INI_SCAN_DIR']);
		} finally {
			putenv($inherited === false ? 'PHP_INI_SCAN_DIR' : "PHP_INI_SCAN_DIR={$inherited}");
		}
	}

	public function testServerLoadsTheIniSettings(): void
	{
		$dir = sys_get_temp_dir() . '/celema-ini-' . bin2hex(random_bytes(4));
		mkdir($dir);
		file_put_contents("{$dir}/index.php", '<?php echo php_ini_scanned_files();');
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$setup = new Setup($dir, '', php: PHP_BINARY);
		$server = Process::start($setup->phpCommand('127.0.0.1', $port, true), $setup->phpEnvironment(false));
		$this->assertInstanceOf(Process::class, $server);

		try {
			$response = false;

			for ($i = 0; $i < 50 && $response === false; $i++) {
				usleep(100_000);
				$response = ErrorTrap::run(static fn(): mixed => file_get_contents("http://127.0.0.1:{$port}/"));
			}

			$this->assertIsString($response);
			$this->assertStringContainsString('/src/ini/cserve.ini', $response);
		} finally {
			$server->close(terminate: true);
			unlink("{$dir}/index.php");
			rmdir($dir);
		}
	}

	public function testEnvironmentNeverInheritsLiveReloadScript(): void
	{
		putenv('CELEMA_LIVE_RELOAD=http://localhost:1/stale.js');

		try {
			$setup = new Setup('/tmp/public', '');

			$this->assertArrayNotHasKey('CELEMA_LIVE_RELOAD', $setup->phpEnvironment(false));
			$this->assertArrayNotHasKey('CELEMA_LIVE_RELOAD', $setup->frankenPhpEnvironment());
		} finally {
			putenv('CELEMA_LIVE_RELOAD');
		}
	}

	#[DataProvider('serverCommands')]
	public function testWatchPassesLiveReloadScriptToTheBackendByDefault(string $command): void
	{
		$output = $this->watch('tests/**/*.php', command: $command);

		$this->assertMatchesRegularExpression(
			'#Live reload script: (http://127\.0\.0\.1:\d+/celema-live-reload\.js)\n.*script=\1#s',
			$output,
		);
		$this->assertMatchesRegularExpression('#Watching \d+ files#', $output);
	}

	public static function serverCommands(): array
	{
		return [['server'], ['frankenphp']];
	}

	#[DataProvider('serverCommands')]
	public function testNoWatchDisablesLiveReloadEvenWithPatternOverrides(string $command): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		$this->assertIsString($address);
		$port = (int) substr($address, (int) strrpos($address, ':') + 1);
		$inherited = getenv('CELEMA_LIVE_RELOAD');
		putenv('CELEMA_LIVE_RELOAD=http://localhost:1/stale.js');

		try {
			// The occupied reload port must not prevent serving without watching.
			$output = $this->watch(
				'tests/**/*.php',
				[
					'--no-watch',
					'--watch-files=src/**/*.php',
					"--reload-port={$port}",
				],
				$command,
			);

			$this->assertMatchesRegularExpression('#^Serving http://127\.0\.0\.1:\d+\nscript=unset\n$#', $output);
		} finally {
			fclose($socket);
			putenv($inherited === false ? 'CELEMA_LIVE_RELOAD' : "CELEMA_LIVE_RELOAD={$inherited}");
		}
	}

	#[DataProvider('serverCommands')]
	public function testWatchFilesOverridesConfiguredPatterns(string $command): void
	{
		$output = $this->watch('no-such-dir/**/*.php', ['--watch-files=src/Options.php'], $command);

		$this->assertStringContainsString('Watching 1 file', $output);
	}

	#[DataProvider('serverCommands')]
	public function testWatchFilesRequiresAValue(string $command): void
	{
		$argv = $_SERVER['argv'];
		$_SERVER['argv'] = ['run', $command, '--watch-files'];

		try {
			$io = new BufferedIo();
			$commands = new Commands([new Server('/tmp/public'), new FrankenPhp('/tmp/public')]);

			$this->assertSame(1, new Runner($commands, $io)->run());
			$this->assertStringContainsString("Option '--watch-files' requires a value", $io->errorOutput());
		} finally {
			$_SERVER['argv'] = $argv;
		}
	}

	public function testWatchUsesTheGivenReloadPort(): void
	{
		$port = $this->freePort();
		$output = $this->watch('tests/**/*.php', ["--reload-port={$port}"]);

		$this->assertStringContainsString("script=http://127.0.0.1:{$port}/celema-live-reload.js", $output);
	}

	public function testWatchRejectsTheServerPortForReload(): void
	{
		$port = $this->freePort();
		$executable = tempnam(sys_get_temp_dir(), 'fake-php-');
		$this->assertIsString($executable);

		try {
			$io = new BufferedIo();
			$exit = (new Server('/tmp/public', executable: $executable))(
				new Args(['--host=127.0.0.1', "--port={$port}", "--reload-port={$port}"]),
				$io,
			);

			$this->assertSame(1, $exit);
			$this->assertStringContainsString(
				'The live reload port must differ from the server port.',
				$io->errorOutput(),
			);
		} finally {
			unlink($executable);
		}
	}

	public function testOpenFlagIsParsed(): void
	{
		$this->assertTrue(Options::from(1983, ['**/*.php'], new Args(['--open']))->open);
		$this->assertTrue(Options::from(1983, ['**/*.php'], new Args(['-o']))->open);
		$this->assertFalse(Options::from(1983, ['**/*.php'], new Args([]))->open);
	}

	public function testBrowserCommandMatchesTheOperatingSystem(): void
	{
		$url = 'http://localhost:1983';

		$this->assertSame(['open', $url], Browser::command($url, 'Darwin'));
		$this->assertSame(['cmd', '/c', 'start', '', $url], Browser::command($url, 'Windows'));
		$this->assertSame(['xdg-open', $url], Browser::command($url, 'Linux'));
	}

	public function testAddressUsesAReachableHost(): void
	{
		$this->assertSame('http://localhost:1983', Address::url('0.0.0.0', 1983));
		$this->assertSame('http://[::1]:1983', Address::url('::1', 1983));
		$this->assertSame('http://127.0.0.1:1983', Address::url('127.0.0.1', 1983));
	}

	public function testReloadPortMustBeValid(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid port 'abc'.");

		Options::from(1983, ['**/*.php'], new Args(['--reload-port=abc']));
	}

	public function testWatchWarnsWhenNoFilesMatch(): void
	{
		$output = $this->watch('no-such-dir/**/*.php');

		$this->assertStringContainsString('No files match the watch patterns: no-such-dir/**/*.php', $output);
	}

	public function testInvalidOptionsReportToStderrAndFail(): void
	{
		$io = new BufferedIo();
		$exit = (new Server('/tmp/public'))(new Args(['--port=foo']), $io);

		$this->assertSame(1, $exit);
		$this->assertSame('', $io->output());
		$this->assertStringContainsString("Invalid port 'foo'.", $io->errorOutput());
	}

	public function testFilterRejectsInvalidRegex(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid filter regex '#oops'.");

		Options::filter('#oops');
	}

	public function testInvalidFilterReportsToStderrAndFails(): void
	{
		$io = new BufferedIo();
		$exit = (new Server('/tmp/public'))(new Args(['--filter=#oops']), $io);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid filter regex '#oops'.", $io->errorOutput());
	}

	public function testPortUnavailableMessageUsesNativeError(): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		$this->assertIsString($address);
		$port = (int) substr($address, (int) strrpos($address, ':') + 1);

		try {
			$message = Ports::unavailableMessage('127.0.0.1', $port);

			$this->assertIsString($message);
			$this->assertStringContainsString("Port 127.0.0.1:{$port} is not available: ", $message);
			$this->assertStringNotContainsString('stream_socket_server', $message);
		} finally {
			fclose($socket);
		}

		$this->assertNull(Ports::unavailableMessage('127.0.0.1', $port));
	}

	public function testPortRejectsInvalidValue(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid port 'foo'.");

		Options::port('foo');
	}

	public function testLiveReloadPortScalesThePublicPortTimesTen(): void
	{
		$port = Ports::liveReloadPort('127.0.0.1', 2130);

		$this->assertIsInt($port);
		$this->assertGreaterThanOrEqual(21_300, $port);
		$this->assertNull(Ports::unavailableMessage('127.0.0.1', $port));
	}

	public function testLiveReloadPortSkipsOccupiedPorts(): void
	{
		$first = Ports::liveReloadPort('127.0.0.1', 1983);
		$this->assertIsInt($first);
		$socket = stream_socket_server("tcp://127.0.0.1:{$first}");
		$this->assertIsResource($socket);

		try {
			$second = Ports::liveReloadPort('127.0.0.1', 1983);

			$this->assertIsInt($second);
			$this->assertGreaterThan($first, $second);
		} finally {
			fclose($socket);
		}
	}

	public function testLiveReloadPortFallsBackWhenTimesTenOverflows(): void
	{
		$port = Ports::liveReloadPort('127.0.0.1', 6913);

		$this->assertIsInt($port);
		$this->assertGreaterThanOrEqual(16_913, $port);
	}

	public function testLiveReloadPortReportsAnExhaustedRange(): void
	{
		// 55534 + 10000 leaves only 65534 and 65535 to try; occupy both.
		$sockets = [];

		foreach ([65_534, 65_535] as $port) {
			$socket = ErrorTrap::run(
				static fn(): mixed => stream_socket_server("tcp://127.0.0.1:{$port}"),
			);

			if (is_resource($socket)) {
				$sockets[] = $socket;
			}
		}

		try {
			$message = Ports::liveReloadPort('127.0.0.1', 55_534);

			$this->assertSame('No free live reload port between 65534 and 65535.', $message);
		} finally {
			foreach ($sockets as $socket) {
				fclose($socket);
			}
		}
	}

	public function testLiveReloadPortRejectsTheMaximumPublicPort(): void
	{
		$message = Ports::liveReloadPort('127.0.0.1', 65_535);

		$this->assertSame('Live reload needs a free port above the public port.', $message);
	}

	public function testWatchingUsesConfiguredPatternsByDefault(): void
	{
		$options = Options::from(1983, ['**/*.php', '**/*.css'], new Args([]));

		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.php', '**/*.css'], $options->watchFiles);
	}

	public function testWatchFilesValueOverridesConfiguredPattern(): void
	{
		$options = Options::from(
			1983,
			['**/*.php', '**/*.css'],
			new Args(['--watch-files=**/*.twig']),
		);

		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.twig'], $options->watchFiles);
	}

	public function testWatchFilesSupportsMultipleValues(): void
	{
		$options = Options::from(
			1983,
			Setup::DEFAULT_WATCH,
			new Args([
				'--watch-files=app/**/*.php',
				'--watch-files=vendor/celema/cms/**/*.{js,css,php}',
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
			new Args([]),
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
				Console::recordException(new RuntimeException('Boom'), trace: true);

				$this->assertTrue(Console::hasException());

				Console::flushException();
				$log = file_get_contents($file);

				$this->assertFalse(Console::hasException());
				$this->assertIsString($log);
				$this->assertStringContainsString(RuntimeException::class . ': Boom', $log);
				$this->assertStringContainsString('in ', $log);
				$this->assertStringContainsString('Trace:', $log);
			});
		});
	}

	public function testConsoleReportsStructuredFrankenPhpException(): void
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
					'celema-exception {"method":"POST","uri":"/api?x=1","lines":["RuntimeException: Boom","in ',
					$log,
				);
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

	/**
	 * Runs the server command in watch mode against a backend that exits at once.
	 *
	 * @param list<string> $args
	 */
	private function watch(string $pattern, array $args = [], string $command = 'server'): string
	{
		$executable = tempnam(sys_get_temp_dir(), 'fake-php-');

		if ($executable === false) {
			$this->fail('Could not create a fake PHP executable.');
		}

		file_put_contents($executable, "#!/bin/sh\nprintf 'script=%s\\n' \"\${CELEMA_LIVE_RELOAD-unset}\" >&2\n");
		chmod($executable, 0o755);
		$port = $this->freePort();
		$argv = $_SERVER['argv'];
		$_SERVER['argv'] = ['run', $command, '--host=127.0.0.1', "--port={$port}", ...$args];

		try {
			$io = new BufferedIo();
			$commands = new Commands([
				new Server('/tmp/public', watch: $pattern, executable: $executable),
				new FrankenPhp('/tmp/public', watch: $pattern, executable: $executable),
			]);
			$exit = new Runner($commands, $io)->run();

			$this->assertSame(0, $exit, $io->errorOutput());

			return $io->output();
		} finally {
			$_SERVER['argv'] = $argv;
			unlink($executable);
		}
	}

	private function freePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = stream_socket_get_name($socket, false);
		fclose($socket);
		$this->assertIsString($address);

		return (int) substr($address, (int) strrpos($address, ':') + 1);
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
