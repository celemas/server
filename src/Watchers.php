<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * @internal
 *
 * @psalm-import-type Binding from Process
 * @psalm-type Watcher = array{stream: closed-resource|resource, handler: callable(string): void, buffer: string}
 */
final class Watchers
{
	/**
	 * @param list<Binding> $bindings
	 *
	 * @return array<int, Watcher>
	 */
	public static function collect(array $bindings): array
	{
		$watchers = [];

		foreach ($bindings as $binding) {
			foreach ($binding['handlers'] as $pipe => $handler) {
				$stream = $binding['process']->pipe($pipe);

				if (!is_resource($stream)) {
					continue;
				}

				stream_set_blocking($stream, false);
				$watchers[(int) $stream] = [
					'stream' => $stream,
					'handler' => $handler,
					'buffer' => '',
				];
			}
		}

		return $watchers;
	}
}
