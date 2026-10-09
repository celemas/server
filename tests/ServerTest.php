<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Console\Runner;
use Celema\Server\Address;
use Celema\Server\Browser;
use Celema\Server\Console;
use Celema\Server\ErrorTrap;
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
		$buffer = new Buffer();
		$io = new Io($buffer);
		$exit = Cli::run(
			new Server('/tmp/public', server: 'frankenphp', frankenphp: '__missing_frankenphp_binary__'),
			$io,
		);

		$this->assertSame(1, $exit);
		$this->assertSame('', $buffer->output());
		$this->assertStringContainsString(
			"The FrankenPHP executable '__missing_frankenphp_binary__' was not found.",
			$buffer->errorOutput(),
		);
	}

	public function testFrankenPhpCommandRejectsInvalidOptions(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$exit = Cli::run(new Server('/tmp/public', server: 'frankenphp'), $io, ['--port=70000']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Port '70000' must be between 1 and 65535.", $buffer->errorOutput());
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
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run(
				new Server('/tmp/public', server: 'frankenphp', routePrefix: '/prefix', frankenphp: $executable),
				$io,
				['--host=127.0.0.1', "--port={$port}"],
			);

			$this->assertSame(0, $exit);
			$output = $buffer->output();
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
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run(new Server('/tmp/public', php: $executable), $io, ['--host=127.0.0.1', "--port={$port}"]);

			$this->assertSame(0, $exit);
			$this->assertStringContainsString('200 GET /test', $buffer->output());
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
			$buffer = new Buffer();
			$io = new Io($buffer);
			$backend = $command === 'builtin'
				? new Server('/tmp/public', php: $executable)
				: new Server('/tmp/public', server: 'frankenphp', frankenphp: $executable);
			$exit = Cli::run($backend, $io, ['--host=127.0.0.1', '--no-watch']);

			$this->assertSame(0, $exit, $buffer->errorOutput());
			$this->assertSame("Serving http://127.0.0.1:2130\n", $buffer->output());
		} finally {
			unlink($executable);
		}
	}

	#[DataProvider('serverCommands')]
	public function testBusyPortSuggestsAnotherPort(string $command): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = (string) stream_socket_get_name($socket, false);
		$port = (int) substr($address, (int) strrpos($address, ':') + 1);

		try {
			$buffer = new Buffer();
			$io = new Io($buffer);
			$backend = $command === 'builtin'
				? new Server('/tmp/public', php: PHP_BINARY)
				: new Server('/tmp/public', server: 'frankenphp', frankenphp: PHP_BINARY);
			$exit = Cli::run($backend, $io, ['--host=127.0.0.1', "--port={$port}", '--no-watch']);
		} finally {
			fclose($socket);
		}

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Port 127.0.0.1:{$port} is not available", $buffer->errorOutput());
		$this->assertStringContainsString('Another server may still be running on it.', $buffer->errorOutput());
		$this->assertSame('', $buffer->output());
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
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run(new Server('/tmp/public', php: $executable), $io, [
				'--host=127.0.0.1',
				"--port={$port}",
				'--no-watch',
				'--quiet',
			]);

			$this->assertSame(0, $exit);
			$this->assertSame("Serving http://127.0.0.1:{$port} (PHP 8.5.0)\n", $buffer->output());
		} finally {
			unlink($executable);
		}
	}

	public function testOptionsKeepTheGivenSettings(): void
	{
		$options = new Options(
			host: '127.0.0.1',
			port: 8080,
			filter: '#health#',
			debug: true,
			quiet: true,
			watchFiles: ['**/*.twig'],
			defaultWatch: ['**/*.php'],
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
		return [['builtin'], ['frankenphp']];
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
		$_SERVER['argv'] = ['run', 'server', $command, '--watch-files'];

		try {
			$buffer = new Buffer();
			$io = new Io($buffer);
			$commands = [new Server('/tmp/public')];

			$this->assertSame(2, new Runner($commands, $io)->run());
			$this->assertStringContainsString("Option '--watch-files' requires a value", $buffer->errorOutput());
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
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run(new Server('/tmp/public', php: $executable), $io, [
				'--host=127.0.0.1',
				"--port={$port}",
				"--reload-port={$port}",
			]);

			$this->assertSame(1, $exit);
			$this->assertStringContainsString(
				'The live reload port must differ from the server port.',
				$buffer->errorOutput(),
			);
		} finally {
			unlink($executable);
		}
	}

	public function testBusyReloadPortIsAnErrorBeforeTheBackendStarts(): void
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = (string) stream_socket_get_name($socket, false);
		$reloadPort = (int) substr($address, (int) strrpos($address, ':') + 1);
		$started = sys_get_temp_dir() . '/celema-backend-started-' . uniqid();
		$executable = tempnam(sys_get_temp_dir(), 'fake-php-');
		$this->assertIsString($executable);
		file_put_contents($executable, "#!/bin/sh\ntouch '{$started}'\n");
		chmod($executable, 0o755);

		try {
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run(new Server('/tmp/public', php: $executable), $io, [
				'--host=127.0.0.1',
				"--port={$this->freePort()}",
				"--reload-port={$reloadPort}",
			]);

			$this->assertSame(1, $exit);
			$this->assertStringContainsString("Port 127.0.0.1:{$reloadPort} is not available", $buffer->errorOutput());
			$this->assertStringContainsString('--reload-port=<port>', $buffer->errorOutput());
			$this->assertFileDoesNotExist($started);
		} finally {
			fclose($socket);
			unlink($executable);

			if (is_file($started)) {
				unlink($started);
			}
		}
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
		$this->expectExceptionMessage("Port '0' must be between 1 and 65535.");

		new Options(reloadPort: 0);
	}

	public function testWatchWarnsWhenNoFilesMatch(): void
	{
		$output = $this->watch('no-such-dir/**/*.php');

		$this->assertStringContainsString('No files match the watch patterns: no-such-dir/**/*.php', $output);
	}

	public function testInvalidOptionsReportToStderrAndFail(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$exit = Cli::run(new Server('/tmp/public'), $io, ['--port=0']);

		$this->assertSame(1, $exit);
		$this->assertSame('', $buffer->output());
		$this->assertStringContainsString("Port '0' must be between 1 and 65535.", $buffer->errorOutput());
	}

	public function testFilterRejectsInvalidRegex(): void
	{
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage("Invalid filter regex '#oops'.");

		new Options(filter: '#oops');
	}

	public function testInvalidFilterReportsToStderrAndFails(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$exit = Cli::run(new Server('/tmp/public'), $io, ['--filter=#oops']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid filter regex '#oops'.", $buffer->errorOutput());
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

	public function testNonNumericPortIsAUsageError(): void
	{
		$buffer = new Buffer();
		$exit = Cli::run(new Server('/tmp/public'), new Io($buffer), ['--port=foo']);

		$this->assertSame(2, $exit);
		$this->assertStringContainsString("Option '--port' expects an integer, got 'foo'", $buffer->errorOutput());
	}

	public function testCommandLineDeclaresTheServerOptions(): void
	{
		$buffer = new Buffer();

		$this->assertSame(
			0,
			new Runner([new Server('/tmp/public')], new Io($buffer))->run(['cserve', 'help', 'server']),
		);
		$help = $buffer->output();
		$this->assertStringContainsString('-o, --open', $help);
		$this->assertStringContainsString('--worker[=<count>]', $help);
		$this->assertStringContainsString('-p=<port>, --port=<port>', $help);
		$this->assertStringContainsString('--no-watch', $help);
		$this->assertStringContainsString('--watch-files=<glob>', $help);
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
		$options = new Options(defaultWatch: ['**/*.php', '**/*.css']);

		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.php', '**/*.css'], $options->watchFiles);
	}

	public function testWatchFilesValueOverridesConfiguredPattern(): void
	{
		$options = new Options(watchFiles: ['**/*.twig'], defaultWatch: ['**/*.php', '**/*.css']);

		$this->assertTrue($options->watch);
		$this->assertSame(['**/*.twig'], $options->watchFiles);
	}

	public function testWatchFilesSupportsMultipleValues(): void
	{
		$options = new Options(watchFiles: ['app/**/*.php', 'vendor/celema/cms/**/*.{js,css,php}']);

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
		$options = new Options(
			defaultWatch: 'app/**/*.php, public/**/*.{js,php,css,jpg,png}, vendor/celema/cms/**/*.{js,css,php}',
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
	private function watch(string $pattern, array $args = [], string $command = 'builtin'): string
	{
		$executable = tempnam(sys_get_temp_dir(), 'fake-php-');

		if ($executable === false) {
			$this->fail('Could not create a fake PHP executable.');
		}

		file_put_contents($executable, "#!/bin/sh\nprintf 'script=%s\\n' \"\${CELEMA_LIVE_RELOAD-unset}\" >&2\n");
		chmod($executable, 0o755);
		$port = $this->freePort();
		$argv = $_SERVER['argv'];
		$_SERVER['argv'] = ['run', 'server', $command, '--host=127.0.0.1', "--port={$port}", ...$args];

		try {
			$buffer = new Buffer();
			$io = new Io($buffer);
			$commands = [
				new Server('/tmp/public', watch: $pattern, php: $executable, frankenphp: $executable),
			];
			$exit = new Runner($commands, $io)->run();

			$this->assertSame(0, $exit, $buffer->errorOutput());

			return $buffer->output();
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
