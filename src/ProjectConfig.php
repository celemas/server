<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * The project's settings for `cserve` in `.cserve/config.ini` of the
 * working directory, overridden by a developer's own, usually ignored by
 * Git, in `.cserve/config.local.ini`. Run scripts configure their
 * commands themselves and do not read them.
 *
 * @internal
 */
final readonly class ProjectConfig
{
	public const string FILE = '.cserve/config.ini';
	public const string LOCAL_FILE = '.cserve/config.local.ini';
	/** The commands that can serve the application. */
	public const array SERVERS = ['server', 'frankenphp'];

	private function __construct(
		/** The command `cserve` runs without one. */
		public string $server = 'server',
	) {}

	/** The settings, defaults without files, or an error message. */
	public static function load(string $cwd): self|string
	{
		$settings = [];

		foreach ([self::FILE, self::LOCAL_FILE] as $name) {
			$values = self::read($cwd, $name);

			if (is_string($values)) {
				return $values;
			}

			$settings = [...$settings, ...$values];
		}

		return new self(...$settings);
	}

	/**
	 * The validated settings of one file, none without it, or an error
	 * message.
	 *
	 * @return array{server?: string}|string
	 */
	private static function read(string $cwd, string $name): array|string
	{
		$file = $cwd . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);

		if (!is_file($file)) {
			return [];
		}

		/** @var array<string, mixed>|false $values */
		$values = ErrorTrap::run(static fn(): array|false => parse_ini_file($file, false, INI_SCANNER_TYPED), $error);

		if ($values === false) {
			return "Failed to read {$name}" . ($error === null ? '.' : ": {$error}");
		}

		// Typos would otherwise go unnoticed.
		$unknown = array_diff(array_keys($values), ['server']);

		if ($unknown !== []) {
			return "Unknown setting '" . implode("', '", $unknown) . "' in {$name}.";
		}

		if (!array_key_exists('server', $values)) {
			return [];
		}

		$server = $values['server'];

		if (!is_string($server) || !in_array($server, self::SERVERS, true)) {
			$value = is_string($server) ? " '{$server}'" : '';

			return "Invalid server{$value} in {$name}: use " . implode(' or ', self::SERVERS) . '.';
		}

		return ['server' => $server];
	}
}
