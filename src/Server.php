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
			$runtime = new Runtime(
				new Setup($this->docroot, $this->routePrefix, $options->watchFiles),
				$options,
				$io,
			);
			// The relayed child output stays a verbatim pipe: it is not
			// markup (request URIs may contain tag-like text), and
			// escaping would strip the request log's colors.
			$phpOutput = function (string $line) use ($options): void {
				$this->echoPhpOutput($line, $options->filter);
			};
			$browserOutput = static function (string $line): void {
				echo $line;
			};

			$result = $options->watch
				? $runtime->watch($phpOutput, $browserOutput)
				: $runtime->serve($phpOutput);

			// Runtime still reports failures as a message string; print it and
			// keep the previous exit-0 behaviour.
			if (is_string($result)) {
				$io->echoln($result);

				return 0;
			}

			return $result;
		} catch (InvalidArgumentException $e) {
			$io->echoln($e->getMessage());

			return 0;
		}
	}

	private function echoPhpOutput(string $output, string $filter): void
	{
		if (preg_match('/^\[.*?\] \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d{1,5}/', $output)) {
			return;
		}

		$openingPos = (int) strpos($output, '[');
		$closingPos = (int) strpos($output, ']');
		$uriPos = (int) strpos($output, '/');

		if ($filter && preg_match($filter, substr($output, $uriPos))) {
			return;
		}

		if ($openingPos === 0 && $closingPos === 25) {
			echo substr($output, 27);

			return;
		}

		echo $output;
	}
}
