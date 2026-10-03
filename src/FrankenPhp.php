<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use InvalidArgumentException;

/** @api */
#[Command('frankenphp', 'Serve the application with FrankenPHP')]
#[Opt(
	'--host',
	'Host to bind FrankenPHP to. Defaults to localhost.',
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
#[Opt('--debug', 'Enable verbose Caddy logs.', short: '-d')]
#[Opt('--quiet', 'Reduce server and live reload output.', short: '-q')]
#[Opt('--open', 'Open the application in the default browser once it responds.', short: '-o')]
#[Opt(
	'--reload-port',
	'Port for live reload. Defaults to ten times the port, or the next free port above.',
	value: 'port',
)]
#[Opt(
	'--worker',
	'Keep the application in memory with FrankenPHP workers (default: 1). Watched changes to files other than CSS or JS restart the workers.',
	value: 'count',
	optionalValue: true,
)]
#[Opt('--no-watch', 'Disable file watching, live reload, and automatic worker restarts.')]
#[Opt(
	'--watch-files',
	'Override the configured watch patterns. Repeat the option or separate patterns with commas. Ignored with --no-watch.',
	value: 'glob',
)]
class FrankenPhp
{
	public function __construct(
		protected readonly string $docroot,
		protected readonly int $port = 1983,
		protected readonly string $routePrefix = '',
		protected readonly array|string $watch = Setup::DEFAULT_WATCH,
		protected readonly string $executable = 'frankenphp',
	) {}

	public function __invoke(Args $args, Io $io): int
	{
		try {
			$options = Options::from($this->port, $this->watch, $args);
			$runtime = new FrankenRuntime(
				new Setup($this->docroot, $this->routePrefix, $this->executable),
				$options,
				$io,
			);
			$output = new FrankenOutput(
				$io,
				$options->filter,
				Setup::terminalColumns(),
				$options->quiet,
				$options->debug,
			);
			$result = $runtime->run($output->line(...));

			if (is_string($result)) {
				$io->error($result);

				return 1;
			}

			return $result;
		} catch (InvalidArgumentException $e) {
			$io->error($e->getMessage());

			return 1;
		}
	}
}
