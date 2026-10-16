<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * The project's settings in `.cserve/config.ini` of the working
 * directory, overridden by a developer's own, usually ignored by Git, in
 * `.cserve/config.local.ini`: the server to run and the `server` command's
 * options, named like on the command line. Both override a run script's
 * arguments, and the command line overrides them.
 *
 * The properties are null when unset. Values are checked for their type
 * here and for their range like the command line's.
 *
 * @psalm-type Settings = array{
 *     server?: string,
 *     host?: string,
 *     port?: int,
 *     filter?: string,
 *     debug?: bool,
 *     quiet?: bool,
 *     open?: bool,
 *     reloadPort?: int,
 *     processes?: int,
 *     worker?: int,
 *     watch?: bool,
 *     companions?: bool,
 *     watchFiles?: list<string>,
 * }
 *
 * @internal
 */
final readonly class ProjectConfig
{
	public const string FILE = '.cserve/config.ini';
	public const string LOCAL_FILE = '.cserve/config.local.ini';
	/** The servers that can serve the application. */
	public const array SERVERS = ['builtin', 'frankenphp'];

	/** The properties by setting name, and the values that fit, for error messages. */
	private const array SETTINGS = [
		'server' => ['server', 'builtin or frankenphp'],
		'host' => ['host', 'a host name or address'],
		'port' => ['port', 'a port number'],
		'filter' => ['filter', "a regex in single quotes, like '#^/assets/#'"],
		'debug' => ['debug', 'true or false'],
		'quiet' => ['quiet', 'true or false'],
		'open' => ['open', 'true or false'],
		'reload-port' => ['reloadPort', 'a port number'],
		'processes' => ['processes', 'a positive integer'],
		'worker' => ['worker', 'true, false, or a count'],
		'watch' => ['watch', 'true or false'],
		'companions' => ['companions', 'true or false'],
		'watch-files' => ['watchFiles', 'a string of glob patterns or a list of them'],
	];

	/** @param ?list<string> $watchFiles */
	// One parameter per setting, like the command line's options.
	// @mago-expect lint:excessive-parameter-list
	private function __construct(
		/** The server to run without a `server` argument. */
		public ?string $server = null,
		public ?string $host = null,
		public ?int $port = null,
		public ?string $filter = null,
		public ?bool $debug = null,
		public ?bool $quiet = null,
		public ?bool $open = null,
		public ?int $reloadPort = null,
		public ?int $processes = null,
		public ?int $worker = null,
		public ?bool $watch = null,
		public ?bool $companions = null,
		public ?array $watchFiles = null,
	) {}

	/** The settings, unset without files, or an error message. */
	public static function load(string $cwd): self|string
	{
		$settings = [];

		foreach ([self::FILE, self::LOCAL_FILE] as $name) {
			$values = self::read($cwd, $name);

			if (is_string($values)) {
				return $values;
			}

			/** @var Settings $settings */
			$settings = [...$settings, ...$values];
		}

		return new self(...$settings);
	}

	/**
	 * The validated settings of one file, none without it, or an error
	 * message.
	 *
	 * @return Settings|string
	 */
	private static function read(string $cwd, string $name): array|string
	{
		$file = $cwd . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);

		if (!is_file($file)) {
			return [];
		}

		$values = ErrorTrap::run(static fn(): array|false => parse_ini_file($file, false, INI_SCANNER_TYPED), $error);

		if ($values === false) {
			return "Failed to read {$name}" . ($error === null ? '.' : ": {$error}");
		}

		// Typos would otherwise go unnoticed.
		$unknown = array_diff(array_keys($values), array_keys(self::SETTINGS));

		if ($unknown !== []) {
			return "Unknown setting '" . implode("', '", $unknown) . "' in {$name}.";
		}

		$settings = [];

		/** @var mixed $value */
		foreach ($values as $setting => $value) {
			[$property, $expected] = self::SETTINGS[$setting];
			$converted = self::convert($property, $value);

			if ($converted === null) {
				$shown = is_string($value) || is_int($value) || is_float($value) ? " '{$value}'" : '';

				return "Invalid {$setting}{$shown} in {$name}: use {$expected}.";
			}

			$settings[$property] = $converted;
		}

		/** @var Settings $settings Converted to the properties' types */
		return $settings;
	}

	/**
	 * The value converted to the property's type, or null if it does not
	 * fit.
	 *
	 * @return string|int|bool|list<string>|null
	 */
	private static function convert(string $property, mixed $value): string|int|bool|array|null
	{
		return match ($property) {
			'server' => is_string($value) && in_array($value, self::SERVERS, true) ? $value : null,
			'host', 'filter' => is_string($value) ? $value : null,
			'port', 'reloadPort', 'processes' => self::integer($value),
			// Like a bare `--worker` and `--worker=0`.
			'worker' => is_bool($value) ? (int) $value : self::integer($value),
			'watchFiles' => self::patterns($value),
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
	 * One string of patterns, or a list of them, as `watch-files[]` lines
	 * give it.
	 *
	 * @return ?list<string>
	 */
	private static function patterns(mixed $value): ?array
	{
		if (is_string($value)) {
			return [$value];
		}

		if (
			!is_array($value)
			|| !array_is_list($value)
			|| !array_all($value, static fn(mixed $pattern): bool => is_string($pattern))
		) {
			return null;
		}

		/** @var list<string> $value Checked by array_all() */
		return $value;
	}
}
