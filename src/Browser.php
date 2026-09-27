<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Opens the served application in the default browser.
 *
 * @internal
 */
final class Browser
{
	private const int WAIT = 5_000_000_000;

	/** Returns an error message, or null once the browser was asked to open the URL. */
	public static function open(string $host, int $port): ?string
	{
		$url = Address::url($host, $port);

		if (!self::reachable($url)) {
			return "Not opening the browser, {$url} did not respond.";
		}

		$process = Process::start(self::command($url, PHP_OS_FAMILY));

		if ($process === null || $process->close() !== 0) {
			return "Could not open {$url} in the browser.";
		}

		return null;
	}

	/** @return list<string> */
	public static function command(string $url, string $os): array
	{
		return match ($os) {
			'Darwin' => ['open', $url],
			// `start` treats the first quoted argument as the window title.
			'Windows' => ['cmd', '/c', 'start', '', $url],
			default => ['xdg-open', $url],
		};
	}

	/** Waits for the backend to accept connections, so the page loads on the first try. */
	private static function reachable(string $url): bool
	{
		$address = 'tcp://' . substr($url, strlen('http://'));
		$deadline = hrtime(true) + self::WAIT;

		while (hrtime(true) < $deadline) {
			/** @var resource|false $socket */
			$socket = ErrorTrap::run(static fn(): mixed => stream_socket_client($address, timeout: 0.2));

			if (is_resource($socket)) {
				fclose($socket);

				return true;
			}

			usleep(50_000);
		}

		return false;
	}
}
