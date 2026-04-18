<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class WatcherOutput
{
	public static function consumeReady(array &$watchers, array $read): void
	{
		foreach ($read as $stream) {
			$id = (int) $stream;
			$chunk = stream_get_contents($stream);

			if ($chunk !== false && $chunk !== '') {
				self::consume($chunk, $watchers[$id]['handler'], $watchers[$id]['buffer']);
			}

			if (!feof($stream)) {
				continue;
			}

			self::flush($watchers[$id]);
			unset($watchers[$id]);
		}
	}

	public static function flushAll(array $watchers): void
	{
		foreach ($watchers as $watcher) {
			self::flush($watcher);
		}
	}

	private static function consume(string $chunk, callable $handler, string &$buffer): void
	{
		$buffer .= $chunk;

		while (($pos = strpos($buffer, "\n")) !== false) {
			$line = substr($buffer, 0, $pos + 1);
			$buffer = substr($buffer, $pos + 1);
			$handler($line);
		}
	}

	private static function flush(array $watcher): void
	{
		if ($watcher['buffer'] !== '') {
			$watcher['handler']($watcher['buffer']);
		}

		if (!is_resource($watcher['stream'])) {
			return;
		}

		fclose($watcher['stream']);
	}
}
