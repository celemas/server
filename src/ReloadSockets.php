<?php

declare(strict_types=1);

namespace Celema\Server;

/** @internal */
final class ReloadSockets
{
	/**
	 * Opens the listening sockets for the reload endpoint. `localhost`
	 * binds both loopback addresses: pages reach the script under their
	 * own host name, which may resolve to either of them.
	 *
	 * @return non-empty-list<resource>|string The sockets, or an error message
	 */
	public static function open(string $host, int $port): array|string
	{
		$servers = [];
		$error = '';

		foreach ($host === 'localhost' ? ['127.0.0.1', '[::1]'] : [$host] as $address) {
			$server = self::bind($address, $port);

			if (is_resource($server)) {
				$servers[] = $server;
			} elseif ($error === '') {
				$error = $server;
			}
		}

		if ($servers === []) {
			return "Failed to start live reload on {$host}:{$port}" . ($error !== '' ? ": {$error}" : '') . '.';
		}

		return $servers;
	}

	/** @return resource|string The socket, or the error */
	private static function bind(string $address, int $port): mixed
	{
		$errorCode = 0;
		$errorMessage = '';
		/** @var resource|false $server */
		$server = ErrorTrap::run(
			static function () use ($address, $port, &$errorCode, &$errorMessage): mixed {
				return stream_socket_server("tcp://{$address}:{$port}", $errorCode, $errorMessage);
			},
			$trapped,
		);

		if ($server !== false) {
			return $server;
		}

		return $errorMessage !== '' ? $errorMessage : (string) $trapped;
	}
}
