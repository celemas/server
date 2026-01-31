<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use Duon\Cli\Command;
use Duon\Cli\Opts;
use Throwable;

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

	public function run(): string|int
	{
		$docroot = $this->docroot;
		$port = (string) $this->port;

		try {
			$sizeStr = trim(exec('stty size'));

			if ($sizeStr) {
				$size = explode(' ', $sizeStr);
				$columns = $size[1];
			} else {
				$columns = '80';
			}
		} catch (Throwable) {
			$columns = '80';
		}

		$opts = new Opts();
		$host = $opts->get('-h', $opts->get('--host', 'localhost'));
		$port = $opts->get('-p', $opts->get('--port', $port));
		$filter = $opts->get('-f', $opts->get('--filter', ''));
		$debugger = $opts->has('-d', $opts->has('--debug'));
		$quiet = $opts->has('-q', $opts->has('--quiet'));

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open(
			($debugger ? 'XDEBUG_SESSION=1 ' : '')
			. 'DUON_CLI_SERVER=1 '
			. "DUON_DOCUMENT_ROOT={$docroot} "
			. "DUON_TERMINAL_COLUMNS={$columns} "
			. "DUON_ROUTE_PREFIX={$this->routePrefix} "
			. "php -S {$host}:{$port} "
			. ($quiet ? '-q ' : '')
			. " -t {$docroot}" . DIRECTORY_SEPARATOR . ' ' . __DIR__ . DIRECTORY_SEPARATOR . 'CliRouter.php ',
			$descriptors,
			$pipes,
		);

		if ($debugger) {
			echo "\033[0;31mXdebug session enabled\033[0m\n";
		}

		if (is_resource($process)) {
			while (!feof($pipes[1])) {
				$output = fgets($pipes[2], 1024);

				if (strlen($output) === 0) {
					break;
				}

				if (!preg_match('/^\[.*?\] \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d{1,5}/', $output)) {
					$openingPos = (int) strpos($output, '[');
					$closingPos = (int) strpos($output, ']');

					if (!$filter || !preg_match($filter, substr($output, (int) strpos($output, '/')))) {
						if ($openingPos === 0 && $closingPos === 25) {
							// If this matches it should be a line outputted by the builtin server.
							// Kind of a hack, but it should work most of the time.
							echo substr($output, 27);
						} else {
							// For example an error_log coming from the user.
							echo $output;
						}
					}
				}
			}

			fclose($pipes[1]);
			proc_close($process);
		}

		return 0;
	}
}
