<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Answers the reload endpoint's requests: the browser script, the
 * Idiomorph module it morphs pages with, the event stream pages
 * subscribe to, and 404 for everything else.
 *
 * @internal
 */
final readonly class ReloadResponse
{
	public const string SCRIPT = '/celema-live-reload.js';
	private const string EVENTS = '/events';
	private const string IDIOMORPH = '/idiomorph.js';

	private function __construct(
		public string $bytes,
		public bool $events = false,
	) {}

	public static function for(string $request): self
	{
		$line = substr($request, 0, (int) strpos($request, "\r\n"));
		[$method, $target] = [...explode(' ', $line, 3), '', ''];
		$path = $method === 'GET' ? parse_url($target, PHP_URL_PATH) : null;

		return match ($path) {
			// No length, the body ends when the connection closes.
			self::EVENTS => new self(self::head('200 OK', 'text/event-stream') . "\r\nretry: 1000\n\n", events: true),
			self::SCRIPT => self::body(
				'200 OK',
				'text/javascript',
				(string) file_get_contents(__DIR__ . '/live-reload.js'),
			),
			self::IDIOMORPH => self::body(
				'200 OK',
				'text/javascript',
				(string) file_get_contents(__DIR__ . '/idiomorph/idiomorph.esm.js'),
			),
			default => self::body('404 Not Found', 'text/plain', ''),
		};
	}

	/** The script URL for pages on the same machine. */
	public static function url(string $host, int $port): string
	{
		return Address::url($host, $port) . self::SCRIPT;
	}

	private static function body(string $status, string $type, string $body): self
	{
		return new self(self::head($status, $type) . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body);
	}

	private static function head(string $status, string $type): string
	{
		return (
			"HTTP/1.1 {$status}\r\n"
				. "Content-Type: {$type}; charset=utf-8\r\n"
				. "Cache-Control: no-store\r\n"
				. "Access-Control-Allow-Origin: *\r\n"
				. "Connection: close\r\n"
		);
	}
}
