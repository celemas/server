<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Arg;
use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use InvalidArgumentException;

/** @api */
#[Command('server', 'Serve the application with the built-in PHP server or FrankenPHP')]
#[Arg(
	'server',
	'builtin or frankenphp. Defaults to the project\'s configured server.',
	optional: true,
)]
#[Opt(
	'--host',
	'Host to bind the server to. Defaults to localhost.',
	short: '-h',
	value: 'host',
)]
#[Opt(
	'--port',
	'Port to listen on.',
	short: '-p',
	value: 'port',
)]
#[Opt('--filter', 'Hide matching request log lines.', short: '-f', value: 'regex')]
#[Opt('--debug', 'builtin: enable an Xdebug session. frankenphp: enable verbose Caddy logs.', short: '-d')]
#[Opt('--quiet', 'Reduce verbose output where supported.', short: '-q')]
#[Opt('--open', 'Open the application in the default browser once it responds.', short: '-o')]
#[Opt(
	'--reload-port',
	'Port for live reload. Defaults to ten times the port, or the next free port above.',
	value: 'port',
)]
#[Opt(
	'--processes',
	'builtin only: serve requests concurrently with the given number of server processes.',
	value: 'count',
	default: '1',
)]
#[Opt(
	'--worker',
	'frankenphp only: keep the application in memory with FrankenPHP workers (default: 1). Watched changes to files other than CSS or JS restart the workers.',
	value: 'count',
	optionalValue: true,
)]
#[Opt('--no-watch', 'Disable file watching, live reload, and automatic worker restarts.')]
#[Opt('--no-companions', 'Do not start the configured companion processes.')]
#[Opt(
	'--watch-files',
	'Override the configured watch patterns. Repeat the option or separate patterns with commas. Ignored with --no-watch.',
	value: 'glob',
)]
class Server
{
	/**
	 * The `server` is the one to run without a `server` argument, unless
	 * the project's `.cserve/config.ini` sets another.
	 *
	 * The built-in server runs with the `php` executable. FrankenPHP runs
	 * with the `frankenphp` executable, or without one, the pinned
	 * `version` from the shared cache, or else `frankenphp` on PATH or the
	 * newest cached version. A missing FrankenPHP is downloaded once the
	 * user agrees.
	 *
	 * The `companions` run alongside the server, like asset watchers: each
	 * name with a command line or a list of arguments.
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

	public function __invoke(Args $args, Io $io): int
	{
		try {
			$server = $this->server($args);
			$options = Options::from($this->port, $this->watch, $args);
			$companions = Companion::validate($this->companions);
			$result = $server === 'frankenphp'
				? $this->frankenPhp($options, $companions, $io)
				: $this->builtin($options, $companions, $io);
		} catch (InvalidArgumentException $e) {
			$result = $e->getMessage();
		}

		// Runtime reports failures as a message string.
		if (is_string($result)) {
			$io->error($result);

			return 1;
		}

		return $result;
	}

	/**
	 * The server to run: the argument, the project's setting, or the
	 * configured default. Options of the other server are rejected
	 * rather than ignored.
	 */
	private function server(Args $args): string
	{
		$config = ProjectConfig::load((string) getcwd());

		if (is_string($config)) {
			throw new InvalidArgumentException($config);
		}

		$server = $args->positional(0) ?? $config->server ?? $this->server;

		if (!in_array($server, ProjectConfig::SERVERS, true)) {
			throw new InvalidArgumentException(
				"Unknown server '{$server}': use " . implode(' or ', ProjectConfig::SERVERS) . '.',
			);
		}

		$foreign = $server === 'frankenphp' ? '--processes' : '--worker';

		if ($args->has($foreign)) {
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
		$output = new PhpOutput($io, $options->filter, Setup::terminalColumns());

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
		$output = new FrankenOutput($io, $options->filter, Setup::terminalColumns(), $options->debug);

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
			return FrankenBinary::resolve($this->frankenphp, $this->version, $io, Setup::interactive(), $interrupt);
		} finally {
			$interrupt->release();
		}
	}
}
