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
	private static ?bool $available = null;

	/**
	 * Whether processes can run in their own group: this needs a separate
	 * PHP for the wrapper that sets the group up, the pcntl and posix
	 * extensions there, and signal handling here, which stops the groups
	 * on Ctrl+C. A terminal no longer reaches them itself.
	 */
	public static function available(): bool
	{
		return self::$available ??=
			DIRECTORY_SEPARATOR === '/'
			&& PHP_BINARY !== ''
			&& function_exists('pcntl_signal')
			&& function_exists('posix_kill')
			&& Capture::output([PHP_BINARY, '-n', self::wrapper(), '--check']) === 'ok';
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

		if ($executable === null) {
			return null;
		}

		return [PHP_BINARY, '-n', self::wrapper(), $executable, ...array_slice($arguments, 1)];
	}

	/** Signals the group the process leads; false when there is none. */
	public static function signal(int $pid, int $signal): bool
	{
		return posix_kill(-$pid, $signal);
	}

	private static function wrapper(): string
	{
		return __DIR__ . DIRECTORY_SEPARATOR . 'group.php';
	}
}
