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

	public function __construct(
		private Io $io,
		private string $filter,
		private int $columns,
	) {}

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

		$status = (int) $fields[1];
		$method = $this->plain($fields[2]);
		$duration = $fields[3];
		$url = $this->plain(urldecode($fields[5]));

		if ($this->filter !== '' && preg_match($this->filter, $url) === 1) {
			return;
		}

		$statusColor = match (true) {
			$status >= 200 && $status < 300 => 'green',
			$status >= 300 && $status < 400 => 'blue',
			$status >= 400 && $status < 500 => 'yellow',
			$status >= 500 => 'red',
			default => 'white',
		};
		$exc = str_contains($fields[4], 'e');
		$xhr = str_contains($fields[4], 'x');
		$flags = ($exc ? '[EXC]' : '') . ($xhr ? '[XHR]' : '');
		$separator = $flags === '' ? '' : ' ';

		$now = microtime(true);
		$timestamp = sprintf('%s.%02d', date('H:i:s', (int) $now), (int) (($now - floor($now)) * 100));

		$spacer = $this->spacer(
			mb_strwidth("{$timestamp} {$status} {$method} {$url}"),
			mb_strwidth("{$flags}{$separator}{$duration}s"),
		);

		$this->io->echoln(
			"<white>{$timestamp}</white> "
			. "<{$statusColor}>{$status}</{$statusColor}> "
			. $this->io->escape($method)
			. ' '
			. "<{$statusColor}>"
			. $this->io->escape($url)
			. "</{$statusColor}>"
			. " <gray>{$spacer}</gray> "
			. ($exc ? '<cyan>[EXC]</cyan>' : '')
			. ($xhr ? '<cyan>[XHR]</cyan>' : '')
			. $separator
			. "<white>{$duration}s</white>",
		);
	}

	private function spacer(int $left, int $right): string
	{
		if ($left > $this->columns) {
			$left %= $this->columns;
		}

		return str_repeat('.', $this->columns - (($left + $right + 2) % $this->columns));
	}

	/**
	 * Strips all control characters, so the widths above match what the
	 * escaped text renders as and one request stays one log line.
	 */
	private function plain(string $text): string
	{
		return (string) preg_replace('/[\x00-\x1F\x7F]/', replacement: '', subject: $text);
	}
}
