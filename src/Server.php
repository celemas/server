<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Args;
use Celema\Console\Command;
use InvalidArgumentException;

/** @api */
class Server extends Command
{
	protected string $name = 'server';
	protected string $description = 'Serve the application on the builtin PHP server';

	public function __construct(
		protected readonly string $docroot,
		protected readonly int $port = 1983,
		protected readonly string $routePrefix = '',
		protected readonly array|string $watch = Setup::DEFAULT_WATCH,
	) {}

	public function help(): void
	{
		$this->helpHeader(withOptions: true);
		$this->helpOption(
			'--host',
			'Host to bind the dev server to. Defaults to localhost.',
			short: '-h',
			value: 'host',
		);
		$this->helpOption(
			'--port',
			'Public port to listen on. When BrowserSync is enabled, the PHP server uses the next port.',
			short: '-p',
			value: 'port',
		);
		$this->helpOption('--filter', 'Hide matching request log lines.', short: '-f', value: 'regex');
		$this->helpOption('--debug', 'Enable an Xdebug session for the PHP server.', short: '-d');
		$this->helpOption('--quiet', 'Reduce verbose output where supported.', short: '-q');
		$this->helpOption(
			'--watch',
			'Run BrowserSync in front of the PHP server. Optional files override the configured watch patterns.',
			short: '-w',
			value: 'file',
			optionalValue: true,
		);
	}

	public function run(Args $args): int
	{
		try {
			$options = Options::from($this->port, $this->watch, $args);
			$runtime = new Runtime(
				new Setup($this->docroot, $this->routePrefix, $options->watchFiles),
				$options,
			);
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
				$this->echoln($result);

				return self::SUCCESS;
			}

			return $result;
		} catch (InvalidArgumentException $e) {
			$this->echoln($e->getMessage());

			return self::SUCCESS;
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
