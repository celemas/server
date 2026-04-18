<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class Watchers
{
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
