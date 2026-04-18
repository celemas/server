<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use Duon\Cli\Command;
use InvalidArgumentException;

/** @psalm-api */
class Server extends Command
{
	protected string $name = 'server';
	protected string $description = 'Serve the application on the builtin PHP server';

	public function __construct(
		protected readonly string $docroot,
		protected readonly int $port = 1983,
		protected readonly string $routePrefix = '',
	) {}

	public function help(): void
	{
		$this->helpHeader(withOptions: true);
		$this->helpOption('-h, --host <host>', 'Host to bind the dev server to. Defaults to localhost.');
		$this->helpOption(
			'-p, --port <port>',
			'Public port to listen on. When BrowserSync is enabled, the PHP server uses the next port.',
		);
		$this->helpOption('-f, --filter <regex>', 'Hide matching request log lines.');
		$this->helpOption('-d, --debug', 'Enable an Xdebug session for the PHP server.');
		$this->helpOption('-q, --quiet', 'Reduce verbose output where supported.');
		$this->helpOption(
			'-w, --watch',
			'Run BrowserSync in front of the PHP server. Requires node and npx.',
		);
	}

	public function run(): string|int
	{
		try {
			$options = ServerOptions::from($this->port);
			$runtime = new ServerRuntime(
				new ServerSupport($this->docroot, $this->routePrefix),
				$options,
			);
			$phpOutput = function (string $line) use ($options): void {
				$this->echoPhpOutput($line, $options->filter);
			};
			$browserOutput = static function (string $line): void {
				echo $line;
			};

			if ($options->watch) {
				return $runtime->watch($phpOutput, $browserOutput);
			}

			return $runtime->serve($phpOutput);
		} catch (InvalidArgumentException $e) {
			return $e->getMessage();
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
