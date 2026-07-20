<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/** @internal */
final class FrankenOutput
{
	private RequestOutput $requests;
	/** @var array<string, int> */
	private array $exceptions = [];

	public function __construct(
		private Io $io,
		string $filter,
		int $columns,
		private bool $quiet,
		private bool $debug,
	) {
		$this->requests = new RequestOutput($io, $filter, $columns);
	}

	public function line(string $line): void
	{
		$line = rtrim($line, "\r\n");
		$entry = json_decode($line, true);

		if (!is_array($entry)) {
			$this->io->echoln($this->io->escape($line));

			return;
		}

		$logger = $entry['logger'] ?? null;

		if (
			is_string($logger)
			&& str_starts_with($logger, 'http.log.access')
			&& ($entry['msg'] ?? null) === 'handled request'
		) {
			if (!$this->request($entry)) {
				$this->io->echoln($this->io->escape($line));
			}

			return;
		}

		$message = $entry['msg'] ?? null;

		if (!is_string($message)) {
			$this->other($entry, $line);

			return;
		}

		if (($entry['logger'] ?? null) === 'frankenphp') {
			if ($this->exception($message)) {
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

	private function request(array $entry): bool
	{
		$request = $entry['request'] ?? null;
		$status = $entry['status'] ?? null;
		$duration = $entry['duration'] ?? null;
		$time = $entry['ts'] ?? null;

		if (
			!is_array($request)
			|| !is_int($status)
			|| !is_int($duration) && !is_float($duration)
			|| !is_int($time) && !is_float($time)
		) {
			return false;
		}

		$method = $request['method'] ?? null;
		$url = $request['uri'] ?? null;

		if (!is_string($method) || !is_string($url)) {
			return false;
		}

		$key = self::key($method, $url);
		$exceptions = $this->exceptions[$key] ?? 0;
		$exception = $exceptions > 0;

		if ($exception) {
			$exceptions--;

			if ($exceptions === 0) {
				unset($this->exceptions[$key]);
			} else {
				$this->exceptions[$key] = $exceptions;
			}
		}

		$this->requests->line(
			$status,
			$method,
			sprintf('%.5f', $duration),
			$url,
			$exception,
			$this->xhr($request['headers'] ?? null),
			(float) $time,
		);

		return true;
	}

	private function exception(string $message): bool
	{
		$prefix = 'celema-exception ';

		if (!str_starts_with($message, $prefix)) {
			return false;
		}

		$context = json_decode(substr($message, strlen($prefix)), true);

		if (!is_array($context)) {
			return false;
		}

		$method = $context['method'] ?? null;
		$url = $context['uri'] ?? null;

		if (!is_string($method) || !is_string($url)) {
			return false;
		}

		$key = self::key($method, $url);
		$this->exceptions[$key] = ($this->exceptions[$key] ?? 0) + 1;

		return true;
	}

	private function xhr(mixed $headers): bool
	{
		if (!is_array($headers)) {
			return false;
		}

		foreach ($headers as $name => $values) {
			if (!is_string($name) || strcasecmp($name, 'X-Requested-With') !== 0) {
				continue;
			}

			if (is_string($values)) {
				return strtolower($values) === 'xmlhttprequest';
			}

			return (
				is_array($values)
				&& isset($values[0])
				&& is_string($values[0])
				&& strtolower($values[0]) === 'xmlhttprequest'
			);
		}

		return false;
	}

	private function other(array $entry, string $line): void
	{
		if (($entry['level'] ?? null) === 'error') {
			$message = is_string($entry['msg'] ?? null) ? $entry['msg'] : $line;
			$error = $entry['error'] ?? null;

			if (is_string($error) && $error !== '') {
				$message .= ": {$error}";
			}

			$this->io->error($this->io->escape($message));

			return;
		}

		if ($this->debug) {
			$this->io->echoln($this->io->escape($line));
		}
	}

	private static function key(string $method, string $url): string
	{
		return strtoupper($method) . "\0" . $url;
	}
}
