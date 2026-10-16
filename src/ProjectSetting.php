<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Converts the values of the project's settings, as INI reads them, to
 * the types of the ProjectConfig properties they set.
 *
 * @internal
 */
final class ProjectSetting
{
	/**
	 * The value converted to the property's type, or null if it does not
	 * fit. A companion's value is false to remove it.
	 *
	 * @param string $cwd The directory relative paths start from
	 * @return string|int|bool|list<string>|null
	 */
	public static function convert(string $property, mixed $value, string $cwd): string|int|bool|array|null
	{
		return match ($property) {
			'docroot' => self::directory($value, $cwd),
			'server' => is_string($value) && in_array($value, ProjectConfig::SERVERS, true) ? $value : null,
			'host', 'filter', 'routePrefix' => is_string($value) ? $value : null,
			'php', 'frankenphp' => is_string($value) && trim($value) !== '' ? $value : null,
			'version' => is_string($value) ? FrankenCache::version($value) : null,
			'port', 'reloadPort', 'processes' => self::integer($value),
			// Like a bare `--worker` and `--worker=0`.
			'worker' => is_bool($value) ? (int) $value : self::integer($value),
			'watchFiles' => is_string($value) ? [$value] : self::arguments($value),
			'companion' => $value === false || is_string($value) && trim($value) !== ''
				? $value
				: self::arguments($value),
			default => is_bool($value) ? $value : null,
		};
	}

	/** Integers, also quoted ones. */
	private static function integer(mixed $value): ?int
	{
		if (is_string($value)) {
			return filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
		}

		return is_int($value) ? $value : null;
	}

	/**
	 * A list of strings, as one `key[]` line per entry gives it.
	 *
	 * @return ?non-empty-list<string>
	 */
	private static function arguments(mixed $value): ?array
	{
		if (
			!is_array($value)
			|| $value === []
			|| !array_is_list($value)
			|| !array_all($value, static fn(mixed $entry): bool => is_string($entry))
		) {
			return null;
		}

		/** @var non-empty-list<string> $value Checked by array_all() */
		return $value;
	}

	/** An existing directory, relative to the working directory unless absolute. */
	private static function directory(mixed $value, string $cwd): ?string
	{
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		$absolute = preg_match('#^([A-Za-z]:)?[/\\\\]#', $value) === 1;
		$path = rtrim($absolute ? $value : $cwd . DIRECTORY_SEPARATOR . $value, '/\\');

		return is_dir($path) ? $path : null;
	}
}
