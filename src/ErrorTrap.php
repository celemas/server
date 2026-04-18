<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class ErrorTrap
{
	public static function run(callable $callback, ?string &$message = null): mixed
	{
		$message = null;
		set_error_handler(static function (int $severity, string $error) use (&$message): bool {
			$message = $error;

			return true;
		});

		try {
			return $callback();
		} finally {
			restore_error_handler();
		}
	}
}
