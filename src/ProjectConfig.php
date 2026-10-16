<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * The project's settings in `.cserve/config.ini` of the working
 * directory, overridden by a developer's own, usually ignored by Git, in
 * `.cserve/config.local.ini`: the `server` command's options, named like
 * on the command line, and the run script's arguments that have no
 * option, with a `companion.<name>` setting per companion process. Both
 * override a run script's arguments, and the command line overrides them.
 *
 * The properties are null when unset. Values are checked for their type
 * here and for their range like the command line's.
 *
 * @psalm-type Command = list<string>|string
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
 *     routePrefix?: string,
 *     php?: string,
 *     frankenphp?: string,
 *     version?: string,
 *     commands?: array<string, Command|false>,
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

	private const string COMPANION = 'companion.';

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
		'route-prefix' => ['routePrefix', 'a path like /site'],
		'php' => ['php', 'an executable'],
		'frankenphp' => ['frankenphp', 'an executable'],
		'version' => ['version', 'a version like 1.12.7'],
	];

	/**
	 * @param ?list<string> $watchFiles
	 * @param array<string, Command|false> $commands
	 */
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
		public ?string $routePrefix = null,
		public ?string $php = null,
		public ?string $frankenphp = null,
		/** The pinned FrankenPHP version, normalized. */
		public ?string $version = null,
		/** The companion commands by name; false removes one. */
		private array $commands = [],
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

			// One file's executable or version replaces both of an earlier one's.
			if (isset($values['frankenphp']) || isset($values['version'])) {
				unset($settings['frankenphp'], $settings['version']);
			}

			/** @var Settings $settings */
			$settings = [
				...$settings,
				...$values,
				'commands' => [...($settings['commands'] ?? []), ...($values['commands'] ?? [])],
			];
		}

		return new self(...$settings);
	}

	/**
	 * The companion commands: a run script's, with the project's added,
	 * replaced, or removed by name.
	 *
	 * @param array<string, Command> $companions
	 * @return array<string, Command>
	 */
	public function commands(array $companions): array
	{
		foreach ($this->commands as $name => $command) {
			if ($command === false) {
				unset($companions[$name]);
			} else {
				$companions[$name] = $command;
			}
		}

		return $companions;
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
		$unknown = array_filter(
			array_map(strval(...), array_keys($values)),
			static fn(string $setting): bool => (
				!array_key_exists($setting, self::SETTINGS)
				&& self::companion($setting) === null
			),
		);

		if ($unknown !== []) {
			return "Unknown setting '" . implode("', '", $unknown) . "' in {$name}.";
		}

		$settings = [];

		/** @var mixed $value */
		foreach ($values as $setting => $value) {
			$setting = (string) $setting;
			$companion = self::companion($setting);
			[$property, $expected] = $companion === null
				? self::SETTINGS[$setting]
				: ['companion', 'a command line, a list of arguments, or false'];
			$converted = ProjectSetting::convert($property, $value);

			if ($converted === null) {
				$shown = is_string($value) || is_int($value) || is_float($value) ? " '{$value}'" : '';

				return "Invalid {$setting}{$shown} in {$name}: use {$expected}.";
			}

			if ($companion === null) {
				$settings[$property] = $converted;
			} else {
				$settings['commands'][$companion] = $converted;
			}
		}

		if (isset($settings['frankenphp'], $settings['version'])) {
			return "Set either frankenphp or version in {$name}, not both.";
		}

		/** @var Settings $settings Converted to the properties' types */
		return $settings;
	}

	/** The name of a companion setting, or null for another setting. */
	private static function companion(string $setting): ?string
	{
		$name = str_starts_with($setting, self::COMPANION) ? substr($setting, strlen(self::COMPANION)) : '';

		return trim($name) === '' ? null : $name;
	}
}
