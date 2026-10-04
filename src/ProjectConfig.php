<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * The project's settings for `cserve` in `.cserve/config.ini` of the
 * working directory. Run scripts configure their commands themselves
 * and do not read it.
 *
 * @internal
 */
final readonly class ProjectConfig
{
	public const string FILE = '.cserve/config.ini';
	/** The commands that can serve the application. */
	public const array SERVERS = ['server', 'frankenphp'];

	private function __construct(
		/** The command `cserve` runs without one. */
		public string $server = 'server',
	) {}

	/** The settings, defaults without a file, or an error message. */
	public static function load(string $cwd): self|string
	{
		$file = $cwd . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::FILE);

		if (!is_file($file)) {
			return new self();
		}

		/** @var array<string, mixed>|false $values */
		$values = ErrorTrap::run(static fn(): array|false => parse_ini_file($file, false, INI_SCANNER_TYPED), $error);

		if ($values === false) {
			return 'Failed to read ' . self::FILE . ($error === null ? '.' : ": {$error}");
		}

		// Typos would otherwise go unnoticed.
		$unknown = array_diff(array_keys($values), ['server']);

		if ($unknown !== []) {
			return "Unknown setting '" . implode("', '", $unknown) . "' in " . self::FILE . '.';
		}

		$server = $values['server'] ?? 'server';

		if (!is_string($server) || !in_array($server, self::SERVERS, true)) {
			$value = is_string($server) ? " '{$server}'" : '';

			return "Invalid server{$value} in " . self::FILE . ': use ' . implode(' or ', self::SERVERS) . '.';
		}

		return new self($server);
	}
}
