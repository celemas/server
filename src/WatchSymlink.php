<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class WatchSymlink
{
	/**
	 * @param list<string> $patterns
	 * @return list<string>
	 */
	public static function expand(array $patterns): array
	{
		$expanded = [];

		foreach ($patterns as $pattern) {
			$expanded[] = $pattern;
			$resolved = self::resolve($pattern);

			if ($resolved !== null) {
				$expanded[] = $resolved;
			}
		}

		return array_values(array_unique($expanded));
	}

	private static function resolve(string $pattern): ?string
	{
		$prefix = self::prefix($pattern);

		if ($prefix === null) {
			return null;
		}

		$path = rtrim($prefix, '/');

		if ($path === '' || !file_exists($path) || !self::hasSymlink($path)) {
			return null;
		}

		$resolved = realpath($path);

		if ($resolved === false) {
			return null;
		}

		$suffix = substr($pattern, strlen($path));

		return $resolved . $suffix;
	}

	private static function prefix(string $pattern): ?string
	{
		$index = strcspn($pattern, '*?{[');

		if ($index === 0) {
			return null;
		}

		$prefix = substr($pattern, 0, $index);

		if ($prefix === '') {
			return null;
		}

		return $prefix;
	}

	private static function hasSymlink(string $path): bool
	{
		$check = $path;

		while ($check !== '' && $check !== '.' && $check !== DIRECTORY_SEPARATOR) {
			if (is_link($check)) {
				return true;
			}

			$parent = dirname($check);

			if ($parent === $check) {
				break;
			}

			$check = $parent;
		}

		return false;
	}
}
