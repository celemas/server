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
#[Opt(
	'--reload-port',
	'Port for live reload in watch mode. Defaults to ten times the port, or the next free port above.',
	value: 'port',
)]
#[Opt(
	'--watch',
	'Serve the live reload script and reload pages on changes. Optional files override the configured watch patterns.',
	short: '-w',
	value: 'file',
	optionalValue: true,
)]
class Server
{
	public function __construct(
		protected readonly string $docroot,
		protected readonly int $port = 1983,
		protected readonly string $routePrefix = '',
		protected readonly array|string $watch = Setup::DEFAULT_WATCH,
		protected readonly string $executable = 'php',
	) {}

	public function __invoke(Args $args, Io $io): int
	{
		try {
			$options = Options::from($this->port, $this->watch, $args);
			$runtime = new PhpRuntime(
				new Setup($this->docroot, $this->routePrefix, php: $this->executable),
				$options,
				$io,
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
