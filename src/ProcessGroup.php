<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Runs processes as leaders of their own process group, through a small
 * wrapper script, so they can be stopped together with every process
 * they start.
 *
 * @internal
 */
final class ProcessGroup
{
	/** @var list<string>|false|null The PHP command for the wrapper, false without one; see php(). */
	private static array|false|null $php = null;

	/**
	 * Whether processes can run in their own group: this needs a separate
	 * PHP for the wrapper that sets the group up, the pcntl and posix
	 * extensions there, and signal handling here, which stops the groups
	 * on Ctrl+C. A terminal no longer reaches them itself.
	 */
	public static function available(): bool
	{
		return self::php() !== null;
	}

	/**
	 * The command that runs the given one through the wrapper, or null
	 * when its executable is not found.
	 *
	 * @param list<string>|string $command
	 * @return ?list<string>
	 */
	public static function command(array|string $command): ?array
	{
		$arguments = is_string($command) ? ['/bin/sh', '-c', $command] : $command;
		$executable = Executable::find($arguments[0] ?? '');
		$php = self::php();

		if ($executable === null || $php === null) {
			return null;
		}

		return [...$php, self::wrapper(), $executable, ...array_slice($arguments, 1)];
	}

	/** Signals the group the process leads; false when there is none. */
	public static function signal(int $pid, int $signal): bool
	{
		return posix_kill(-$pid, $signal);
	}

	/**
	 * The PHP command that runs the wrapper, or null when this PHP cannot.
	 * The wrapper runs without ini files, which keep the project's and
	 * Xdebug's settings out of it. Distributions like Debian build posix
	 * as a shared module that only an ini file loads, so the extensions
	 * the wrapper misses are loaded explicitly.
	 *
	 * @return ?list<string>
	 */
	private static function php(): ?array
	{
		$supported =
			DIRECTORY_SEPARATOR === '/'
			&& PHP_BINARY !== ''
			&& function_exists('pcntl_signal')
			&& function_exists('posix_kill');
		self::$php ??= $supported ? self::wrapperPhp() ?? false : false;

		return self::$php === false ? null : self::$php;
	}

	/** @return ?list<string> */
	private static function wrapperPhp(): ?array
	{
		$php = [PHP_BINARY, '-n'];
		$check = Capture::output([...$php, self::wrapper(), '--check']);

		if ($check === 'ok') {
			return $php;
		}

		if ($check === null || preg_match('/^[a-z]+( [a-z]+)*$/D', $check) !== 1) {
			return null;
		}

		// Without ini files, PHP only knows its built-in extension directory.
		$php = [...$php, '-d', 'extension_dir=' . (string) ini_get('extension_dir')];

		foreach (explode(' ', $check) as $extension) {
			$php = [...$php, '-d', "extension={$extension}"];
		}

		// A module that fails to load prints a warning instead of `ok`.
		return Capture::output([...$php, self::wrapper(), '--check']) === 'ok' ? $php : null;
	}

	private static function wrapper(): string
	{
		return __DIR__ . DIRECTORY_SEPARATOR . 'group.php';
	}
}
