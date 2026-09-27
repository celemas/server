<?php

declare(strict_types=1);

namespace Celema\Server;

/** @internal */
final class Address
{
	/** The URL a browser on this machine reaches the host and port at. */
	public static function url(string $host, int $port): string
	{
		// Browsers cannot connect to a wildcard address.
		$host = match ($host) {
			'0.0.0.0', '::', '[::]' => 'localhost',
			default => $host,
		};

		if (str_contains($host, ':') && !str_starts_with($host, '[')) {
			$host = "[{$host}]";
		}

		return "http://{$host}:{$port}";
	}
}
