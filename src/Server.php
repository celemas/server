<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use InvalidArgumentException;

/** @api */
#[Command('server', 'Serve the application on the builtin PHP server')]
#[Opt(
	'--host',
	'Host to bind the dev server to. Defaults to localhost.',
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
#[Opt('--debug', 'Enable an Xdebug session for the PHP server.', short: '-d')]
#[Opt('--quiet', 'Reduce verbose output where supported.', short: '-q')]
#[Opt('--open', 'Open the application in the default browser once it responds.', short: '-o')]
#[Opt(
	'--reload-port',
	'Port for live reload. Defaults to ten times the port, or the next free port above.',
	value: 'port',
)]
#[Opt(
	'--processes',
	'Serve requests concurrently with the given number of server processes.',
	value: 'count',
	default: '1',
)]
#[Opt('--no-watch', 'Disable file watching and live reload.')]
#[Opt('--no-companions', 'Do not start the configured companion processes.')]
#[Opt(
	'--watch-files',
	'Override the configured watch patterns. Repeat the option or separate patterns with commas. Ignored with --no-watch.',
	value: 'glob',
)]
class Server
{
	/**
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
		protected readonly string $executable = 'php',
		protected readonly array $companions = [],
	) {}

	public function __invoke(Args $args, Io $io): int
	{
		try {
			$options = Options::from($this->port, $this->watch, $args);
			$runtime = new PhpRuntime(
				new Setup($this->docroot, $this->routePrefix, php: $this->executable),
				$options,
				$io,
				Companion::validate($this->companions),
			);
			$phpOutput = new PhpOutput($io, $options->filter, Setup::terminalColumns());
			$result = $runtime->run($phpOutput->line(...));

			// Runtime reports failures as a message string.
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
