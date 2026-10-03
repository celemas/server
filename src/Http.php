<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Helpers for the response headers of PHP's http stream wrapper.
 *
 * @internal
 */
final class Http
{
	/** GitHub's API rejects requests without a user agent. */
	public const string AGENT = 'User-Agent: celema-server';

	/**
	 * The status of the last response; redirects add one per hop.
	 *
	 * @param array<array-key, mixed> $headers
	 */
	public static function status(array $headers): int
	{
		$status = 0;

		/** @var mixed $line */
		foreach ($headers as $line) {
			if (is_string($line) && preg_match('/^HTTP\/\S+ (\d{3})/', $line, $match) === 1) {
				$status = (int) $match[1];
			}
		}

		return $status;
	}

	/** @param list<string> $headers */
	public static function header(array $headers, string $name): ?string
	{
		$value = null;

		foreach ($headers as $line) {
			[$key, $rest] = [...explode(':', $line, 2), ''];

			if (strcasecmp(trim($key), $name) === 0) {
				$value = trim($rest);
			}
		}

		return $value;
	}
}
