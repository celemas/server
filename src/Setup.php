<?php

declare(strict_types=1);

namespace Celema\Server;

use Throwable;

/** @internal */
final readonly class Setup
{
	public const DEFAULT_WATCH = ['**/*.{php,js,css,sql,tpql}'];

	public function __construct(
		private string $docroot,
		private string $routePrefix,
		private string $frankenPhp = 'frankenphp',
		private string $php = 'php',
	) {}

	public function missingFrankenPhp(): bool
	{
		return !$this->commandAvailable($this->frankenPhp);
	}

	/** @return array<string, string> */
	public function phpEnvironment(bool $debug, ?string $liveReload = null): array
	{
		$environment = $this->environment('1', $liveReload);

		if ($debug) {
			$environment['XDEBUG_SESSION'] = '1';
		}

		return $environment;
	}

	/** @return list<string> */
	public function phpCommand(string $host, int $port, bool $quiet): array
	{
		$command = [$this->php, '-S', "{$host}:{$port}"];

		if ($quiet) {
			$command[] = '-q';
		}

		$command[] = '-t';
		$command[] = $this->docroot;
		$command[] = __DIR__ . DIRECTORY_SEPARATOR . 'CliRouter.php';

		return $command;
	}

	/** @return list<string> */
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

	/**
	 * The configuration for a route prefix or worker mode, or null when the
	 * plain `php-server` command suffices. In worker mode, the admin API
	 * listens on the given loopback port so changes can restart the worker.
	 *
	 * Like `php-server --listen`, the server binds to the host and answers
	 * every Host header: the host of a Caddy site address would only match
	 * the Host header, with the listener open on all interfaces.
	 */
	public function frankenPhpCaddyfile(string $host, int $port, bool $debug, ?int $adminPort = null): ?string
	{
		$prefix = rtrim($this->routePrefix, '/');

		if ($prefix === '' && $adminPort === null) {
			return null;
		}

		$admin = $adminPort === null ? 'off' : self::caddyToken("127.0.0.1:{$adminPort}");
		$debugOption = $debug ? "\tdebug\n" : '';
		$address = self::caddyToken("http://:{$port}");
		$bind = self::caddyToken($host);
		$docroot = self::caddyToken($this->docroot);
		$indent = $prefix === '' ? "\t" : "\t\t";
		$phpServer = $adminPort === null ? "{$indent}php_server\n" : $this->workerServer($indent);

		if ($prefix !== '') {
			$files = self::caddyToken($prefix . '/*');
			$prefix = self::caddyToken($prefix);
			$phpServer =
				"\troute {\n"
				. "\t\t@prefix path {$prefix} {$files}\n"
				. "\t\turi @prefix strip_prefix {$prefix}\n"
				. $phpServer
				. "\t}\n";
		}

		return (
			"{\n"
				. "\tadmin {$admin}\n"
				. "\tauto_https off\n"
				. "\tpersist_config off\n"
				. "\tfrankenphp\n"
				. $debugOption
				. "}\n"
				. "{$address} {\n"
				. "\tbind {$bind}\n"
				. "\troot * {$docroot}\n"
				. $phpServer
				. "\tlog {\n"
				. "\t\toutput stderr\n"
				. "\t\tformat json\n"
				. "\t}\n"
				. "}\n"
		);
	}

	/**
	 * One worker for the front controller: requests are handled one after
	 * another, and a restart after a change is quick and deterministic.
	 */
	private function workerServer(string $indent): string
	{
		$file = self::caddyToken(rtrim($this->docroot, '/\\') . DIRECTORY_SEPARATOR . 'index.php');

		return (
			"{$indent}php_server {\n"
				. "{$indent}\tworker {\n"
				. "{$indent}\t\tfile {$file}\n"
				. "{$indent}\t\tnum 1\n"
				. "{$indent}\t}\n"
				. "{$indent}}\n"
		);
	}

	/** @return array<string, string> */
	public function frankenPhpEnvironment(?string $liveReload = null): array
	{
		return $this->environment('frankenphp', $liveReload);
	}

	public static function terminalColumns(): int
	{
		// No stty on Windows; without a terminal it only prints an error.
		if (DIRECTORY_SEPARATOR === '\\' || !stream_isatty(STDIN)) {
			return 80;
		}

		try {
			$output = exec('stty size 2>/dev/null');
			$size = trim($output === false ? '' : $output);
			$columns = (int) (explode(' ', $size)[1] ?? 0);

			return $columns > 0 ? $columns : 80;
		} catch (Throwable) {
			return 80;
		}
	}

	/** @return array<string, string> */
	private function environment(string $server, ?string $liveReload): array
	{
		$environment = array_merge(getenv(), [
			'CELEMA_CLI_SERVER' => $server,
			'CELEMA_DOCUMENT_ROOT' => $this->docroot,
			'CELEMA_ROUTE_PREFIX' => $this->routePrefix,
		]);

		// Never inherited: pages must only include the script while
		// this command serves it.
		unset($environment['CELEMA_LIVE_RELOAD']);

		$environment['PHP_INI_SCAN_DIR'] = self::iniScanDir($environment['PHP_INI_SCAN_DIR'] ?? null);

		if ($liveReload !== null) {
			$environment['CELEMA_LIVE_RELOAD'] = $liveReload;
		}

		return $environment;
	}

	/**
	 * Adds the directory of the server's ini settings to the directories
	 * PHP scans for additional ini files. A leading separator keeps the
	 * default directory, where extensions like Xdebug are usually set up.
	 * An empty value disables the default, and stays without it.
	 */
	private static function iniScanDir(?string $inherited): string
	{
		$dir = __DIR__ . DIRECTORY_SEPARATOR . 'ini';

		return match ($inherited) {
			null => PATH_SEPARATOR . $dir,
			'' => $dir,
			default => $inherited . PATH_SEPARATOR . $dir,
		};
	}

	private static function caddyToken(string $value): string
	{
		return '"' . addcslashes($value, "\\\"\r\n\t") . '"';
	}

	private function commandAvailable(string $command): bool
	{
		$output = [];
		$exitCode = 1;
		$windows = DIRECTORY_SEPARATOR === '\\';
		$finder = $windows ? 'where' : 'which';
		$null = $windows ? 'NUL' : '/dev/null';
		exec("{$finder} " . escapeshellarg($command) . " 2>{$null}", $output, $exitCode);

		return $exitCode === 0;
	}
}
