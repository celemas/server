<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class Relay
{
	public static function run(array $bindings): void
	{
		$watchers = Watchers::collect($bindings);

		while ($watchers !== []) {
			$read = array_column($watchers, 'stream');
			$write = null;
			$except = null;
			$changed = ErrorTrap::run(
				static fn(): mixed => stream_select($read, $write, $except, 0, 200_000),
			);

			if ($changed === false) {
				break;
			}

			if ($changed > 0) {
				WatcherOutput::consumeReady($watchers, $read);
			}

			if (self::stopped($bindings)) {
				break;
			}
		}

		WatcherOutput::flushAll($watchers);
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
