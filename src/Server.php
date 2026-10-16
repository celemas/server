<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Arg;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use InvalidArgumentException;

/** @api */
#[Command('server', 'Serve the application with the built-in PHP server or FrankenPHP')]
class Server
{
	/**
	 * The `server` is the one to run without a `server` argument. The
	 * project's settings in `.cserve/config.ini` override it, the `port`,
	 * and the `watch` patterns, and set the other options of the command
	 * line, which overrides them in turn.
	 *
	 * The built-in server runs with the `php` executable. FrankenPHP runs
	 * with the `frankenphp` executable, or without one, the pinned
	 * `version` from the shared cache, or else `frankenphp` on PATH or the
	 * newest cached version. A missing FrankenPHP is downloaded once the
	 * user agrees.
	 *
	 * The `companions` run alongside the server, like asset watchers: each
	 * name with a command line or a list of arguments.
	 *
	 * @param list<string>|string $watch
	 * @param array<string, list<string>|string> $companions
	 */
	// One parameter per setting keeps the run scripts' named arguments simple.
	// @mago-expect lint:excessive-parameter-list
	public function __construct(
		protected readonly string $docroot,
		protected readonly int $port = 2130,
		protected readonly string $routePrefix = '',
		protected readonly array|string $watch = Setup::DEFAULT_WATCH,
		protected readonly array $companions = [],
		protected readonly string $server = 'builtin',
		protected readonly string $php = 'php',
		protected readonly ?string $frankenphp = null,
		protected readonly ?string $version = null,
	) {}

	/** @param list<string> $watchFiles */
	// The parameters are the command line's options.
	// @mago-expect lint:excessive-parameter-list
	public function __invoke(
		Io $io,
		#[Arg("builtin or frankenphp. Defaults to the project's configured server.")]
		?string $server = null,
		#[Opt('Host to bind the server to. Defaults to localhost.', short: '-h')]
		?string $host = null,
		#[Opt('Port to listen on.', short: '-p')]
		?int $port = null,
		#[Opt('Hide matching request log lines.', short: '-f', value: 'regex')]
		?string $filter = null,
		#[Opt('builtin: enable an Xdebug session. frankenphp: enable verbose Caddy logs.', short: '-d')]
		?bool $debug = null,
		#[Opt('Reduce verbose output where supported.', short: '-q')]
		?bool $quiet = null,
		#[Opt('Open the application in the default browser once it responds.', short: '-o')]
		?bool $open = null,
		#[Opt('Port for live reload. Defaults to ten times the port, or the next free port above.', value: 'port')]
		?int $reloadPort = null,
		#[Opt(
			'builtin only: serve requests concurrently with the given number of server processes (default: 1).',
			value: 'count',
		)]
		?int $processes = null,
		#[Opt(
			'frankenphp only: keep the application in memory with FrankenPHP workers (default: 1, 0 for none). Watched changes to files other than CSS or JS restart the workers.',
			value: 'count',
			bare: '1',
		)]
		?int $worker = null,
		#[Opt('Watch files for live reload and automatic worker restarts. On by default.')]
		?bool $watch = null,
		#[Opt('Start the configured companion processes. On by default.')]
		?bool $companions = null,
		#[Opt(
			'Override the configured watch patterns. Repeat the option or separate patterns with commas. Ignored with --no-watch.',
			value: 'glob',
		)]
		array $watchFiles = [],
	): int {
		try {
			$config = ProjectConfig::load((string) getcwd());

			if (is_string($config)) {
				throw new InvalidArgumentException($config);
			}

			$server = $this->server($server ?? $config->server, $processes, $worker);
			// The project's settings for the other server only apply to that one.
			$options = new Options(
				host: $host ?? $config->host ?? 'localhost',
				port: $port ?? $config->port ?? $this->port,
				filter: $filter ?? $config->filter ?? '',
				debug: $debug ?? $config->debug ?? false,
				quiet: $quiet ?? $config->quiet ?? false,
				watch: $watch ?? $config->watch ?? true,
				workers: $server === 'frankenphp' ? $worker ?? $config->worker : null,
				processes: $server === 'builtin' ? $processes ?? $config->processes : null,
				open: $open ?? $config->open ?? false,
				reloadPort: $reloadPort ?? $config->reloadPort,
				companions: $companions ?? $config->companions ?? true,
				watchFiles: $watchFiles === [] ? $config->watchFiles ?? [] : $watchFiles,
				defaultWatch: $this->watch,
			);
			$commands = Companion::validate($this->companions);
			$result = $server === 'frankenphp'
				? $this->frankenPhp($options, $commands, $io)
				: $this->builtin($options, $commands, $io);
		} catch (InvalidArgumentException $e) {
			$result = $e->getMessage();
		}

		// Runtime reports failures as a message string.
		if (is_string($result)) {
			$io->error('%s', $result);

			return 1;
		}

		return $result;
	}

	/**
	 * The server to run: the argument or the project's setting, else the
	 * configured default. Options of the other server on the command line
	 * are rejected rather than ignored.
	 */
	private function server(?string $server, ?int $processes, ?int $worker): string
	{
		$server ??= $this->server;

		if (!in_array($server, ProjectConfig::SERVERS, true)) {
			throw new InvalidArgumentException(
				"Unknown server '{$server}': use " . implode(' or ', ProjectConfig::SERVERS) . '.',
			);
		}

		$foreign = match (true) {
			$server === 'frankenphp' && $processes !== null => '--processes',
			$server === 'builtin' && $worker !== null => '--worker',
			default => null,
		};

		if ($foreign !== null) {
			$needed = $server === 'frankenphp' ? 'builtin' : 'frankenphp';

			throw new InvalidArgumentException(
				"{$foreign} needs the {$needed} server: run 'server {$needed} {$foreign}'.",
			);
		}

		return $server;
	}

	/** @param array<string, list<string>|string> $companions */
	private function builtin(Options $options, array $companions, Io $io): int|string
	{
		$runtime = new PhpRuntime(
			new Setup($this->docroot, $this->routePrefix, php: $this->php),
			$options,
			$io,
			$companions,
		);
		$output = new PhpOutput($io, $options->filter, $io->width());

		return $runtime->run($output->line(...));
	}

	/** @param array<string, list<string>|string> $companions */
	private function frankenPhp(Options $options, array $companions, Io $io): int|string
	{
		$binary = $this->binary($io);

		if (is_string($binary)) {
			return $binary;
		}

		$runtime = new FrankenRuntime(
			new Setup($this->docroot, $this->routePrefix, $binary->path),
			$options,
			$io,
			$companions,
		);
		$output = new FrankenOutput($io, $options->filter, $io->width(), $options->debug);

		return $runtime->run($output->line(...));
	}

	private function binary(Io $io): FrankenBinary|string
	{
		if ($this->frankenphp !== null && $this->version !== null) {
			return 'Configure either a FrankenPHP executable or a version, not both.';
		}

		// A download canceled with Ctrl+C leaves no partial file.
		$interrupt = Interrupt::catch();

		try {
			return FrankenBinary::resolve($this->frankenphp, $this->version, $io, $io->interactive(), $interrupt);
		} finally {
			$interrupt->release();
		}
	}
}
