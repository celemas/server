<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class WatchBrace
{
	/**
	 * @param list<string> $patterns
	 * @return list<string>
	 */
	public static function expandList(array $patterns): array
	{
		$expanded = [];

		foreach ($patterns as $pattern) {
			foreach (self::expand($pattern) as $value) {
				$expanded[] = $value;
			}
		}

		return array_values(array_unique($expanded));
	}

	/** @return list<string> */
	private static function expand(string $pattern): array
	{
		$open = strpos($pattern, '{');
		$close = strpos($pattern, '}');

		if ($open === false || $close === false || $close <= $open) {
			return [$pattern];
		}

		$prefix = substr($pattern, 0, $open);
		$suffix = substr($pattern, $close + 1);
		$inside = substr($pattern, $open + 1, $close - $open - 1);
		$values = array_map('trim', explode(',', $inside));
		$expanded = [];

		foreach ($values as $value) {
			if ($value === '') {
				continue;
			}

			$expanded[] = $prefix . $value . $suffix;
		}

		if ($expanded === []) {
			return [$pattern];
		}

		return $expanded;
	}
}
