<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/** @internal */
final class FrankenOutput
{
	private FrankenRequestOutput $requests;

	public function __construct(
		private Io $io,
		string $filter,
		int $columns,
		private bool $quiet,
		private bool $debug,
	) {
		$this->requests = new FrankenRequestOutput($io, $filter, $columns);
	}

	public function line(string $line): void
	{
		$line = rtrim($line, "\r\n");
		$entry = json_decode($line, true);

		if (!is_array($entry)) {
			$this->io->echoln($this->io->escape($line));

			return;
		}

		$logger = self::str($entry, 'logger');

		if (
			$logger !== null
				&& str_starts_with($logger, 'http.log.access')
				&& ($entry['msg'] ?? null) === 'handled request'
		) {
			if (!$this->requests->line($entry)) {
				$this->io->echoln($this->io->escape($line));
			}

			return;
		}

		$message = self::str($entry, 'msg');

		if ($message === null) {
			$this->other($entry, $line);

			return;
		}

		if ($logger === 'frankenphp') {
			if ($this->requests->exception($message)) {
				return;
			}

			if ($message !== 'FrankenPHP started 🐘' || !$this->quiet) {
				$this->io->echoln($this->io->escape($message));
			}

			return;
		}

		if (str_starts_with($message, 'Caddy serving PHP app on ')) {
			if (!$this->quiet) {
				$this->io->echoln($this->io->escape($message));
			}

			return;
		}

		$this->other($entry, $line);
	}

	private function other(array $entry, string $line): void
	{
		if (($entry['level'] ?? null) === 'error') {
			$message = self::str($entry, 'msg') ?? $line;
			$error = self::str($entry, 'error');

			if ($error !== null && $error !== '') {
				$message .= ": {$error}";
			}

			$this->io->error($this->io->escape($message));

			return;
		}

		if ($this->debug) {
			$this->io->echoln($this->io->escape($line));
		}
	}

	private static function str(array $entry, string $key): ?string
	{
		/** @psalm-suppress MixedAssignment -- log entries are unvalidated JSON */
		$value = $entry[$key] ?? null;

		return is_string($value) ? $value : null;
	}
}
