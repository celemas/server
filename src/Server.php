<?php

declare(strict_types=1);

namespace FiveOrbs\Core\Server;

use FiveOrbs\Cli\Command;
use FiveOrbs\Cli\Opts;
use Throwable;

/** @psalm-api */
class Server extends Command
{
	protected string $name = 'server';
	protected string $description = 'Serve the application on the builtin PHP server';

	public function __construct(
		protected readonly string $docroot,
		protected readonly int $port = 1983,
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
		$debugger = $opts->get('-d', $opts->get('--debug'));
		$host = $opts->get('-h', $opts->get('--host', 'localhost'));
		$port = $opts->get('-p', $opts->get('--port', $port));
		$filter = $opts->get('-f', $opts->get('--filter', ''));
		$quiet = $opts->has('-q');

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open(
			($debugger ? 'XDEBUG_SESSION=1 ' : '') .
			'FIVEORBS_CLI_SERVER=1 ' .
			"FIVEORBS_DOCUMENT_ROOT={$docroot} " .
				"FIVEORBS_TERMINAL_COLUMNS={$columns} " .
				"php -S {$host}:{$port} " .
				($quiet ? '-q ' : '') .
				" -t {$docroot}" . DIRECTORY_SEPARATOR . ' ' . __DIR__ . DIRECTORY_SEPARATOR . 'CliRouter.php ',
			$descriptors,
			$pipes,
		);

		if (is_resource($process)) {
			while (!feof($pipes[1])) {
				$output = fgets($pipes[2], 1024);

				if (strlen($output) === 0) {
					break;
				}

				if (!preg_match('/^\[.*?\] \d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}:\d{1,5}/', $output)) {
					$pos = (int) strpos($output, ']');

					if (!$filter || !preg_match($filter, substr($output, (int) strpos($output, '/')))) {
						echo substr($output, $pos + 2);
					}
				}
			}

			fclose($pipes[1]);
			proc_close($process);
		}

		return 0;
	}
}
