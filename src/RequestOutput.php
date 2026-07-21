<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/** @internal */
final readonly class RequestOutput
{
	public function __construct(
		private Io $io,
		private string $filter,
		private int $columns,
	) {}

	public function line(
		int $status,
		string $method,
		string $duration,
		string $url,
		string $flags = '--',
	): void {
		$method = $this->plain($method);
		$url = $this->plain(urldecode($url));

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
		$exception = str_contains($flags, 'e');
		$xhr = str_contains($flags, 'x');
		$labels = ($exception ? '[EXC]' : '') . ($xhr ? '[XHR]' : '');
		$separator = $labels === '' ? '' : ' ';
		$time = microtime(true);
		$timestamp = sprintf(
			'%s.%02d',
			date('H:i:s', (int) $time),
			(int) (($time - floor($time)) * 100),
		);
		$spacer = $this->spacer(
			mb_strwidth("{$timestamp} {$status} {$method} {$url}"),
			mb_strwidth("{$labels}{$separator}{$duration}s"),
		);

		$this->io->echoln(
			"<dim>{$timestamp}</dim> "
			. "<{$statusColor}>{$status}</{$statusColor}> "
			. $this->io->escape($method)
			. ' '
			. "<{$statusColor}>"
			. $this->io->escape($url)
			. "</{$statusColor}>"
			. " <gray>{$spacer}</gray> "
			. ($exception ? '<cyan>[EXC]</cyan>' : '')
			. ($xhr ? '<cyan>[XHR]</cyan>' : '')
			. $separator
			. "<dim>{$duration}s</dim>",
		);
	}

	private function spacer(int $left, int $right): string
	{
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
