<?php

declare(strict_types=1);

namespace Celema\Core\Server;

/** @internal */
final class Ports
{
	public static function unavailableMessage(string $host, int $port): ?string
	{
		$errorCode = 0;
		$errorMessage = '';
		$server = ErrorTrap::run(
			static function () use ($host, $port, &$errorCode, &$errorMessage): mixed {
				return stream_socket_server("tcp://{$host}:{$port}", $errorCode, $errorMessage);
			},
			$trapped,
		);

		if ($server === false) {
			$message = "Port {$host}:{$port} is not available";
			// The native error message; the trapped warning as fallback.
			$detail = $errorMessage !== '' ? $errorMessage : (string) $trapped;

			if ($detail !== '') {
				$message .= ": {$detail}";
			}

			return $message . '.';
		}

		if (is_resource($server)) {
			fclose($server);
		}

		return null;
	}

	/**
	 * Picks the BrowserSync backend port: ten times the public port,
	 * which keeps clear of neighboring dev servers like Vite on the
	 * next port, then upwards until a free port is found.
	 */
	public static function backendPort(string $host, int $port): int|string
	{
		$start = $port * 10;

		if ($start > 65_535) {
			$start = $port + 10_000;
		}

		if ($start > 65_535) {
			$start = $port + 1;
		}

		if ($start > 65_535) {
			return 'BrowserSync needs a free backend port above the public port.';
		}

		$last = min($start + 100, 65_535);

		for ($candidate = $start; $candidate <= $last; $candidate++) {
			if (self::unavailableMessage($host, $candidate) === null) {
				return $candidate;
			}
		}

		return "No free BrowserSync backend port between {$start} and {$last}.";
	}
}
