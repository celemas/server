<?php

declare(strict_types=1);

namespace Celema\Server;

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

	/**
	 * The PHP server forks the given number of processes to serve
	 * requests concurrently. One process is the server's default, which
	 * also overrides an inherited setting; PHP rejects a count of one.
	 * The `iniDir` holds the project's settings, see ProjectIni.
	 *
	 * @return array<string, string>
	 */
	public function phpEnvironment(
		bool $debug,
		?string $liveReload = null,
		?int $processes = null,
		?string $iniDir = null,
	): array {
		$environment = $this->environment('1', $liveReload, $iniDir);

		if ($debug) {
			$environment['XDEBUG_SESSION'] = '1';
		}

		if ($processes === 1) {
			unset($environment['PHP_CLI_SERVER_WORKERS']);
		} elseif ($processes !== null) {
			$environment['PHP_CLI_SERVER_WORKERS'] = (string) $processes;
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

	/**
	 * Queries the version of the PHP executable; without ini files, which
	 * keeps extensions like Xdebug from adding output.
	 *
	 * @return list<string>
	 */
	public function phpVersionCommand(): array
	{
		return [$this->php, '-n', '-r', 'echo PHP_VERSION;'];
	}

	/** @return list<string> */
	public function frankenPhpCommand(string $config): array
	{
		return [$this->frankenPhp, 'run', '--config', $config, '--adapter', 'caddyfile'];
	}

	/** @return list<string> */
	public function frankenPhpVersionCommand(): array
	{
		return [$this->frankenPhp, 'version'];
	}

	/**
	 * Lists the extensions of FrankenPHP's embedded PHP, see FrankenProbe.
	 *
	 * @return list<string>
	 */
	public function frankenPhpProbeCommand(): array
	{
		return [$this->frankenPhp, 'php-cli', __DIR__ . DIRECTORY_SEPARATOR . 'probe.php'];
	}

	/**
	 * The FrankenPHP configuration. When watching in worker mode, the
	 * admin API listens on the given loopback port so changes can restart
	 * the workers; otherwise it is off.
	 *
	 * Like `php-server --listen`, the server binds to the host and answers
	 * every Host header: the host of a Caddy site address would only match
	 * the Host header, with the listener open on all interfaces. Responses
	 * are compressed like those of `php-server`, without Brotli, which not
	 * every build includes.
	 */
	public function frankenPhpCaddyfile(
		string $host,
		int $port,
		bool $debug,
		?int $adminPort = null,
		?int $workers = null,
	): string {
		$prefix = rtrim($this->routePrefix, '/');
		$admin = $adminPort === null ? 'off' : self::caddyToken("127.0.0.1:{$adminPort}");
		$debugOption = $debug ? "\tdebug\n" : '';
		$address = self::caddyToken("http://:{$port}");
		$bind = self::caddyToken($host);
		$docroot = self::caddyToken($this->docroot);
		$indent = $prefix === '' ? "\t" : "\t\t";
		$phpServer = $workers === null ? "{$indent}php_server\n" : $this->workerServer($indent, $workers);

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
				. "\tencode zstd gzip\n"
				. $phpServer
				. "\tlog {\n"
				. "\t\toutput stderr\n"
				. "\t\tformat json\n"
				. "\t}\n"
				. "}\n"
		);
	}

	private function workerServer(string $indent, int $workers): string
	{
		$file = self::caddyToken(rtrim($this->docroot, '/\\') . DIRECTORY_SEPARATOR . 'index.php');

		return (
			"{$indent}php_server {\n"
				. "{$indent}\tworker {\n"
				. "{$indent}\t\tfile {$file}\n"
				. "{$indent}\t\tnum {$workers}\n"
				. "{$indent}\t}\n"
				. "{$indent}}\n"
		);
	}

	/**
	 * The `iniDir` holds the project's settings, see ProjectIni.
	 *
	 * @return array<string, string>
	 */
	public function frankenPhpEnvironment(?string $liveReload = null, ?string $iniDir = null): array
	{
		return $this->environment('frankenphp', $liveReload, $iniDir);
	}

	/** @return array<string, string> */
	private function environment(string $server, ?string $liveReload, ?string $iniDir): array
	{
		$environment = array_merge(getenv(), [
			'CELEMA_CLI_SERVER' => $server,
			'CELEMA_DOCUMENT_ROOT' => $this->docroot,
			'CELEMA_ROUTE_PREFIX' => $this->routePrefix,
		]);

		// Never inherited: pages must only include the script while
		// this command serves it.
		unset($environment['CELEMA_LIVE_RELOAD']);

		$environment['PHP_INI_SCAN_DIR'] = self::iniScanDir($environment['PHP_INI_SCAN_DIR'] ?? null, $iniDir);

		if ($liveReload !== null) {
			$environment['CELEMA_LIVE_RELOAD'] = $liveReload;
		}

		return $environment;
	}

	/**
	 * Adds the directory of the server's ini settings to the directories
	 * PHP scans for additional ini files, followed by the project's, whose
	 * settings thus win. A leading separator keeps the default directory,
	 * where extensions like Xdebug are usually set up. An empty value
	 * disables the default, and stays without it.
	 */
	private static function iniScanDir(?string $inherited, ?string $iniDir): string
	{
		$dir = __DIR__ . DIRECTORY_SEPARATOR . 'ini';

		if ($iniDir !== null) {
			$dir .= PATH_SEPARATOR . $iniDir;
		}

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
}
