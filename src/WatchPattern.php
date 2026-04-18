<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use InvalidArgumentException;

/** @internal */
final class WatchPattern
{
	public static function normalize(array|string $watch): string
	{
		if (is_string($watch)) {
			if (trim($watch) === '') {
				throw new InvalidArgumentException('Watch patterns cannot be empty.');
			}

			return $watch;
		}

		$patterns = [];

		foreach ($watch as $pattern) {
			if (!is_string($pattern)) {
				throw new InvalidArgumentException('Watch patterns must be strings.');
			}

			$pattern = trim($pattern);

			if ($pattern === '') {
				continue;
			}

			$patterns[] = $pattern;
		}

		if ($patterns === []) {
			throw new InvalidArgumentException('Watch patterns cannot be empty.');
		}

		return implode(', ', $patterns);
	}
}
