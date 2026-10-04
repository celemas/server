<?php

declare(strict_types=1);

namespace Celema\Server;

/** @internal */
final class Ports
{
	/** @param string $hint Appended to the message, like what to do about it. */
	public static function unavailableMessage(string $host, int $port, string $hint = ''): ?string
	{
		$errorCode = 0;
		$errorMessage = '';
		/** @var resource|false $server */
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

			return $hint === '' ? "{$message}." : "{$message}. {$hint}";
		}

		fclose($server);

		return null;
	}

	/** A free port the operating system picks, for endpoints nobody types in. */
	public static function ephemeral(string $host = '127.0.0.1'): int|string
	{
		$errorCode = 0;
		$errorMessage = '';
		/** @var resource|false $server */
		// An arrow function would only fill copies of the error variables.
		$server = ErrorTrap::run(
			static function () use ($host, &$errorCode, &$errorMessage): mixed {
				return stream_socket_server("tcp://{$host}:0", $errorCode, $errorMessage);
			},
			$trapped,
		);

		if ($server === false) {
			return "No free port on {$host}: " . ($errorMessage !== '' ? $errorMessage : (string) $trapped);
		}

		$name = (string) stream_socket_get_name($server, false);
		fclose($server);

		return (int) substr($name, (int) strrpos($name, ':') + 1);
	}

	/**
	 * Picks the live reload port: ten times the public port, which
	 * keeps clear of neighboring dev servers like Vite on the next
	 * port, then upwards until a free port is found. Derived from the
	 * public port, it stays the same across restarts, so open pages
	 * reconnect.
	 */
	public static function liveReloadPort(string $host, int $port): int|string
	{
		$start = $port * 10;

		if ($start > 65_535) {
			$start = $port + 10_000;
		}

		if ($start > 65_535) {
			$start = $port + 1;
		}

		if ($start > 65_535) {
			return 'Live reload needs a free port above the public port.';
		}

		$last = min($start + 100, 65_535);

		for ($candidate = $start; $candidate <= $last; $candidate++) {
			if (self::unavailableMessage($host, $candidate) === null) {
				return $candidate;
			}
		}

		return "No free live reload port between {$start} and {$last}.";
	}
}
