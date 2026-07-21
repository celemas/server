<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use InvalidArgumentException;
use Throwable;

/** @internal */
final readonly class Setup
{
	public const DEFAULT_WATCH = ['**/*.{php,js,css}'];

	public function __construct(
		private string $docroot,
		private string $routePrefix,
		private array $watch = self::DEFAULT_WATCH,
		private string $frankenPhp = 'frankenphp',
	) {}

	public static function backendPort(int $port): int
	{
		if ($port >= 65_535) {
			throw new InvalidArgumentException(
				'BrowserSync needs a free backend port after the public port.',
			);
		}

		return $port + 1;
	}

	public function missingBrowserSyncDependencies(): array
	{
		$missing = [];

		foreach (['node', 'npx'] as $command) {
			if ($this->commandAvailable($command)) {
				continue;
			}

			$missing[] = $command;
		}

		return $missing;
	}

	public function missingFrankenPhp(): bool
	{
		return !$this->commandAvailable($this->frankenPhp);
	}

	public function portUnavailableMessage(string $host, int $port): ?string
	{
		$errorCode = 0;
		$errorMessage = '';
		$server = ErrorTrap::run(
			static function () use ($host, $port, &$errorCode, &$errorMessage): mixed {
				return stream_socket_server("tcp://{$host}:{$port}", $errorCode, $errorMessage);
			},
			$trapped,
		);

		if ($server === false) {
			$message = "Port {$host}:{$port} is not available";
			// The native error message; the trapped warning as fallback.
			$detail = $errorMessage !== '' ? $errorMessage : (string) $trapped;

			if ($detail !== '') {
				$message .= ": {$detail}";
			}

			return $message . '.';
		}

		if (is_resource($server)) {
			fclose($server);
		}

		return null;
	}

	public function phpEnvironment(bool $debugger): array
	{
		$environment = array_merge((array) getenv(), [
			'CELEMA_CLI_SERVER' => '1',
			'CELEMA_DOCUMENT_ROOT' => $this->docroot,
			'CELEMA_ROUTE_PREFIX' => $this->routePrefix,
		]);

		if ($debugger) {
			$environment['XDEBUG_SESSION'] = '1';
		}

		return $environment;
	}

	public function phpCommand(string $host, int $port, bool $quiet): array
	{
		$command = ['php', '-S', "{$host}:{$port}"];

		if ($quiet) {
			$command[] = '-q';
		}

		$command[] = '-t';
		$command[] = $this->docroot;
		$command[] = __DIR__ . DIRECTORY_SEPARATOR . 'CliRouter.php';

		return $command;
	}

	public function frankenPhpCommand(
		string $host,
		int $port,
		bool $debug,
		?string $config = null,
	): array {
		if ($config !== null) {
			return [
				$this->frankenPhp,
				'run',
				'--config',
				$config,
				'--adapter',
				'caddyfile',
			];
		}

		$command = [
			$this->frankenPhp,
			'php-server',
			'--root',
			$this->docroot,
			'--listen',
			"{$host}:{$port}",
			'--access-log',
		];

		if ($debug) {
			$command[] = '--debug';
		}

		return $command;
	}

	public function frankenPhpCaddyfile(string $host, int $port, bool $debug): ?string
	{
		$prefix = rtrim($this->routePrefix, '/');

		if ($prefix === '') {
			return null;
		}

		$debugOption = $debug ? "\tdebug\n" : '';
		$address = self::caddyToken("http://{$host}:{$port}");
		$docroot = self::caddyToken($this->docroot);
		$files = self::caddyToken($prefix . '/*');
		$prefix = self::caddyToken($prefix);

		return (
			"{\n"
			. "\tadmin off\n"
			. "\tauto_https off\n"
			. "\tpersist_config off\n"
			. "\tfrankenphp\n"
			. $debugOption
			. "}\n"
			. "{$address} {\n"
			. "\troot * {$docroot}\n"
			. "\troute {\n"
			. "\t\t@prefix path {$prefix} {$files}\n"
			. "\t\turi @prefix strip_prefix {$prefix}\n"
			. "\t\tphp_server\n"
			. "\t}\n"
			. "\tlog {\n"
			. "\t\toutput stderr\n"
			. "\t\tformat json\n"
			. "\t}\n"
			. "}\n"
		);
	}

	public function browserSyncCommand(string $host, int $port, int $backendPort, bool $quiet): array
	{
		$command = [
			'npx',
			'browser-sync',
			'start',
			'--proxy',
			"http://{$host}:{$backendPort}",
		];

		foreach ($this->watch as $pattern) {
			$command[] = '--files';
			$command[] = $pattern;
		}

		$command[] = '--port';
		$command[] = (string) $port;
		$command[] = '--host';
		$command[] = $host;
		$command[] = '--no-ui';
		$command[] = '--no-notify';
		$command[] = '--no-open';
		$command[] = '--reload-delay';
		$command[] = '100';
		$command[] = '--reload-debounce';
		$command[] = '300';

		if ($quiet) {
			$command[] = '--logLevel';
			$command[] = 'silent';
		}

		return $command;
	}

	public function frankenPhpEnvironment(): array
	{
		return array_merge((array) getenv(), [
			'CELEMA_CLI_SERVER' => 'frankenphp',
			'CELEMA_DOCUMENT_ROOT' => $this->docroot,
			'CELEMA_ROUTE_PREFIX' => $this->routePrefix,
		]);
	}

	public static function terminalColumns(): int
	{
		// No stty on Windows; without a terminal it only prints an error.
		if (DIRECTORY_SEPARATOR === '\\' || !stream_isatty(STDIN)) {
			return 80;
		}

		try {
			$size = trim(exec('stty size 2>/dev/null') ?: '');
			$columns = (int) (explode(' ', $size)[1] ?? 0);

			return $columns > 0 ? $columns : 80;
		} catch (Throwable) {
			return 80;
		}
	}

	private static function caddyToken(string $value): string
	{
		return '"' . addcslashes($value, "\\\"\r\n\t") . '"';
	}

	private function commandAvailable(string $command): bool
	{
		$output = [];
		$exitCode = 1;
		exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

		return $exitCode === 0;
	}
}
