<?php

declare(strict_types=1);

namespace Celema\Core\Server;

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
	'Public port to listen on. When BrowserSync is enabled, the PHP server uses the next port.',
	short: '-p',
	value: 'port',
)]
#[Opt('--filter', 'Hide matching request log lines.', short: '-f', value: 'regex')]
#[Opt('--debug', 'Enable an Xdebug session for the PHP server.', short: '-d')]
#[Opt('--quiet', 'Reduce verbose output where supported.', short: '-q')]
#[Opt(
	'--watch',
	'Run BrowserSync in front of the PHP server. Optional files override the configured watch patterns.',
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
	) {}

	public function __invoke(Args $args, Io $io): int
	{
		try {
			$options = Options::from($this->port, $this->watch, $args);
			$runtime = new PhpRuntime(
				new Setup($this->docroot, $this->routePrefix, $options->watchFiles),
				$options,
				$io,
			);
			$phpOutput = new PhpOutput($io, $options->filter, Setup::terminalColumns());
			// BrowserSync's output passes through verbatim; it colors
			// and formats its own lines.
			$browserOutput = static function (string $line): void {
				echo $line;
			};

			$result = $options->watch
				? $runtime->watch($phpOutput->line(...), $browserOutput)
				: $runtime->serve($phpOutput->line(...));

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
