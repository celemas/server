<?php

declare(strict_types=1);

namespace Celema\Core\Server;

/** @internal */
final class Relay
{
	public static function run(array $bindings): void
	{
		$watchers = Watchers::collect($bindings);

		while ($watchers !== []) {
			if (self::consume($watchers, 200_000) === false) {
				break;
			}

			if (self::stopped($bindings)) {
				self::drain($watchers);

				break;
			}
		}

		WatcherOutput::flushAll($watchers);
	}

	/**
	 * A dying process may have written output after the last select
	 * round; read it before closing, so its final lines are not lost.
	 */
	private static function drain(array &$watchers): void
	{
		while ($watchers !== []) {
			$changed = self::consume($watchers, 0);

			if (!is_int($changed) || $changed === 0) {
				return;
			}
		}
	}

	private static function consume(array &$watchers, int $microseconds): int|false
	{
		$read = array_column($watchers, 'stream');
		$write = null;
		$except = null;
		$changed = ErrorTrap::run(
			static fn(): mixed => stream_select($read, $write, $except, 0, $microseconds),
		);

		if (!is_int($changed) || $changed < 1) {
			return $changed === false ? false : 0;
		}

		WatcherOutput::consumeReady($watchers, $read);

		return $changed;
	}

	private static function stopped(array $bindings): bool
	{
		foreach ($bindings as $binding) {
			if ($binding['process']->running()) {
				continue;
			}

			return true;
		}

		return false;
	}
}
