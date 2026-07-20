<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/**
 * Renders the relayed output of the PHP dev-server process.
 *
 * The CLI router reports every handled request as a plain structured
 * `celema-request` line (see functions.php); those become colored
 * request log lines, rendered here in the server command so the console
 * Io decides about colors. The PHP server's own connection and request
 * lines are dropped, timestamp prefixes are trimmed, and everything
 * else passes through escaped, so child output cannot inject markup or
 * terminal escape sequences.
 *
 * @internal
 */
final readonly class PhpOutput
{
	/** The fixed '[Sun Jul 20 17:12:05 2026] ' prefix of PHP server lines. */
	private const int TIMESTAMP = 27;

	private RequestOutput $requests;

	public function __construct(
		private Io $io,
		string $filter,
		int $columns,
	) {
		$this->requests = new RequestOutput($io, $filter, $columns);
	}

	public function line(string $line): void
	{
		$line = rtrim($line, "\r\n");

		// The PHP server's own connection and request lines.
		if (preg_match('/^\[[^\]]+\] (\[[0-9a-f:.]+\]|\d{1,3}(\.\d{1,3}){3}):\d{1,5}/i', $line)) {
			return;
		}

		if (($line[0] ?? '') === '[' && strpos($line, ']') === (self::TIMESTAMP - 2)) {
			$line = substr($line, self::TIMESTAMP);
		}

		if (str_starts_with($line, 'celema-request ')) {
			$this->request($line);

			return;
		}

		$this->io->echoln($this->io->escape($line));
	}

	private function request(string $line): void
	{
		$fields = explode(' ', $line, limit: 6);

		if (
			count($fields) < 6
			|| preg_match('/^\d+$/', $fields[1]) !== 1
			|| preg_match('/^[\d.]+$/', $fields[3]) !== 1
			|| preg_match('/^[ex-]{2}$/', $fields[4]) !== 1
		) {
			$this->io->echoln($this->io->escape($line));

			return;
		}

		$this->requests->line(
			(int) $fields[1],
			$fields[2],
			$fields[3],
			$fields[5],
			$fields[4],
		);
	}
}
