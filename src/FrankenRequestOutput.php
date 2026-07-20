<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/** @internal */
final class FrankenRequestOutput
{
	private RequestOutput $output;
	/** @var array<string, int> */
	private array $exceptions = [];

	public function __construct(Io $io, string $filter, int $columns)
	{
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
		$flags = ($exception ? 'e' : '-') . ($this->xhr($request['headers'] ?? null) ? 'x' : '-');
		$this->output->line($status, $method, sprintf('%.5f', $duration), $url, $flags);

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

		$key = self::key($method, $url);
		$this->exceptions[$key] = ($this->exceptions[$key] ?? 0) + 1;

		return true;
	}

	private function takeException(string $method, string $url): bool
	{
		$key = self::key($method, $url);
		$count = $this->exceptions[$key] ?? 0;

		if ($count === 0) {
			return false;
		}

		if ($count === 1) {
			unset($this->exceptions[$key]);
		} else {
			$this->exceptions[$key] = $count - 1;
		}

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

	private static function key(string $method, string $url): string
	{
		return strtoupper($method) . "\0" . $url;
	}
}
