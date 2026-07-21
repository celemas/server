<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/** @internal */
final class FrankenRequestOutput
{
	/** Bounds pending markers whose request never reaches the access log. */
	private const int MAX_PENDING = 100;

	private RequestOutput $output;
	/** @var array<string, list<list<string>>> */
	private array $exceptions = [];

	public function __construct(
		private Io $io,
		string $filter,
		int $columns,
	) {
		$this->output = new RequestOutput($io, $filter, $columns);
	}

	public function line(array $entry): bool
	{
		$request = $entry['request'] ?? null;
		$status = $entry['status'] ?? null;
		$duration = $entry['duration'] ?? null;

		if (!is_array($request) || !is_int($status) || !is_int($duration) && !is_float($duration)) {
			return false;
		}

		$method = $request['method'] ?? null;
		$url = $request['uri'] ?? null;

		if (!is_string($method) || !is_string($url)) {
			return false;
		}

		$exception = $this->takeException($method, $url);
		$flags =
			($exception !== null ? 'e' : '-') . ($this->xhr($request['headers'] ?? null) ? 'x' : '-');
		$this->output->line($status, $method, sprintf('%.5f', $duration), $url, $flags);

		if ($exception !== null) {
			$this->write($exception);
		}

		return true;
	}

	public function exception(string $message): bool
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

		$details = $context['lines'] ?? null;
		$lines = is_array($details)
			? array_values(array_filter($details, is_string(...)))
			: [];

		$key = self::key($method, $url);

		if (!isset($this->exceptions[$key]) && count($this->exceptions) >= self::MAX_PENDING) {
			unset($this->exceptions[(string) array_key_first($this->exceptions)]);
		}

		$this->exceptions[$key][] = $lines;

		return true;
	}

	/** @return list<string>|null */
	private function takeException(string $method, string $url): ?array
	{
		$key = self::key($method, $url);

		if (!isset($this->exceptions[$key])) {
			return null;
		}

		$lines = array_shift($this->exceptions[$key]);

		if ($this->exceptions[$key] === []) {
			unset($this->exceptions[$key]);
		}

		return $lines;
	}

	/** @param list<string> $lines */
	private function write(array $lines): void
	{
		foreach ($lines as $line) {
			$this->io->echoln($this->io->escape($line));
		}
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

	private static function key(string $method, string $url): string
	{
		return strtoupper($method) . "\0" . $url;
	}
}
