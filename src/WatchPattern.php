<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use InvalidArgumentException;

/** @internal */
final class WatchPattern
{
	/** @return list<string> */
	public static function list(array|string $watch): array
	{
		if (is_string($watch)) {
			$patterns = self::fromArray(self::split($watch));
		} else {
			$patterns = self::fromArray($watch);
		}

		return WatchBrace::expandList(WatchSymlink::expand($patterns));
	}

	/** @param list<mixed> $watch
	 * @return list<string>
	 */
	private static function fromArray(array $watch): array
	{
		$patterns = [];

		foreach ($watch as $rawPattern) {
			if (!is_string($rawPattern)) {
				throw new InvalidArgumentException('Watch patterns must be strings.');
			}

			foreach (self::split($rawPattern) as $pattern) {
				if ($pattern === '') {
					continue;
				}

				$patterns[] = $pattern;
			}
		}

		if ($patterns === []) {
			throw new InvalidArgumentException('Watch patterns cannot be empty.');
		}

		return $patterns;
	}

	/** @return list<string> */
	private static function split(string $watch): array
	{
		$parts = [];
		$buffer = '';
		$depth = 0;
		$length = strlen($watch);

		for ($i = 0; $i < $length; $i++) {
			$char = $watch[$i];

			if ($char === '{') {
				$depth++;
				$buffer .= $char;

				continue;
			}

			if ($char === '}') {
				if ($depth > 0) {
					$depth--;
				}

				$buffer .= $char;

				continue;
			}

			if ($char === ',' && $depth === 0) {
				$parts[] = trim($buffer);
				$buffer = '';

				continue;
			}

			$buffer .= $char;
		}

		$parts[] = trim($buffer);

		return $parts;
	}
}
