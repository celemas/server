<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * @internal
 *
 * @psalm-import-type Binding from Process
 * @psalm-import-type Watcher from Watchers
 */
final class Relay
{
	/**
	 * Relays the output of the processes until one of them stops or the
	 * command is interrupted. Companions only have their output relayed;
	 * when one exits, the others keep running.
	 *
	 * @param list<Binding> $bindings
	 * @param list<Companion> $companions
	 */
	public static function run(
		array $bindings,
		?LiveReload $liveReload = null,
		?Interrupt $interrupt = null,
		array $companions = [],
	): void {
		$watchers = Watchers::collect([
			...$bindings,
			...array_map(static fn(Companion $companion): array => $companion->binding(), $companions),
		]);

		while ($watchers !== []) {
			// A signal also interrupts the select call, which then fails.
			if (self::consume($watchers, 200_000, $liveReload) === false || $interrupt?->received()) {
				break;
			}

			$liveReload?->poll();

			foreach ($companions as $companion) {
				$companion->check();
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
	 *
	 * @param array<int, Watcher> $watchers
	 */
	private static function drain(array &$watchers): void
	{
		// Bounded, as companions may keep writing.
		for ($round = 0; $round < 100 && $watchers !== []; $round++) {
			$changed = self::consume($watchers, 0);

			if (!is_int($changed) || $changed === 0) {
				return;
			}
		}
	}

	/** @param array<int, Watcher> $watchers */
	private static function consume(array &$watchers, int $microseconds, ?LiveReload $liveReload = null): int|false
	{
		// Streams of listed watchers are always open; a watcher is
		// removed from the list when its stream gets closed.
		/** @var list<resource> $read */
		$read = [...array_column($watchers, 'stream'), ...($liveReload?->streams() ?? [])];
		$write = null;
		$except = null;
		$changed = ErrorTrap::run(
			static fn(): mixed => stream_select($read, $write, $except, 0, $microseconds),
		);

		if (!is_int($changed) || $changed < 1) {
			return $changed === false ? false : 0;
		}

		$output = [];
		$sockets = [];

		foreach ($read as $stream) {
			if (isset($watchers[(int) $stream])) {
				$output[] = $stream;
			} else {
				$sockets[] = $stream;
			}
		}

		WatcherOutput::consumeReady($watchers, $output);
		$liveReload?->handle($sockets);

		return $changed;
	}

	/** @param list<Binding> $bindings */
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
