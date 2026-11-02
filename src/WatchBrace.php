<?php

declare(strict_types=1);

namespace Celema\Server;

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
		$close = strpos($pattern, '}');
		$open = $close === false ? false : strrpos(substr($pattern, 0, $close), '{');

		if ($close === false || $open === false) {
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

			foreach (self::expand($prefix . $value . $suffix) as $alternative) {
				$expanded[] = $alternative;
			}
		}

		if ($expanded === []) {
			return [$pattern];
		}

		return $expanded;
	}
}
