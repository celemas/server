<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * A minimal HTTP server for the live reload script and its
 * Server-Sent Events stream; ReloadResponse answers the requests.
 *
 * It runs inside Relay's select loop and never waits on a socket:
 * requests are read once select reports them readable.
 *
 * @internal
 */
final class ReloadEndpoint
{
	private const int MAX_REQUEST = 8192;
	private const int WRITE_TIMEOUT = 2;

	/** @var array<int, array{stream: resource, buffer: string}> */
	private array $requests = [];

	/** @var array<int, resource> */
	private array $clients = [];

	/** @param non-empty-list<resource> $servers */
	private function __construct(
		private array $servers,
	) {}

	public static function listen(string $host, int $port): self|string
	{
		$servers = ReloadSockets::open($host, $port);

		return is_string($servers) ? $servers : new self($servers);
	}

	/**
	 * The listening sockets and all open connections, to be selected
	 * for reading.
	 *
	 * @return list<resource>
	 */
	public function streams(): array
	{
		return [...$this->servers, ...array_column($this->requests, 'stream'), ...array_values($this->clients)];
	}

	/** @param list<resource> $ready Streams from streams() that select reported readable */
	public function handle(array $ready): void
	{
		foreach ($ready as $stream) {
			$id = (int) $stream;

			if (in_array($stream, $this->servers, true)) {
				$this->accept($stream);
			} elseif (isset($this->requests[$id])) {
				$this->read($id, $stream);
			} elseif (isset($this->clients[$id])) {
				$this->drain($id, $stream);
			}
		}
	}

	/** Sends the event to all connected pages and returns their number. */
	public function broadcast(string $event, string $data = ''): int
	{
		foreach ($this->clients as $id => $client) {
			/** @var int|false $written */
			$written = ErrorTrap::run(static fn(): mixed => fwrite($client, "event: {$event}\ndata: {$data}\n\n"));

			if ($written === false || $written === 0) {
				unset($this->clients[$id]);
				fclose($client);
			}
		}

		return count($this->clients);
	}

	public function close(): void
	{
		foreach ([...array_column($this->requests, 'stream'), ...$this->clients, ...$this->servers] as $stream) {
			fclose($stream);
		}

		$this->requests = [];
		$this->clients = [];
	}

	/**
	 * Event stream clients send nothing after their request; only a
	 * confirmed end of stream means the page is gone.
	 *
	 * @param resource $stream
	 */
	private function drain(int $id, mixed $stream): void
	{
		$chunk = fread($stream, self::MAX_REQUEST);

		if ($chunk === false || $chunk === '' && feof($stream)) {
			unset($this->clients[$id]);
			fclose($stream);
		}
	}

	/**
	 * Responses like the Idiomorph module exceed what a non-blocking write
	 * takes at once, so they are written in blocking mode. Pages read them
	 * right away; the timeout keeps a stalled client from holding up the
	 * relay loop for long.
	 *
	 * @param resource $stream
	 */
	private function respond(mixed $stream, string $request): void
	{
		$response = ReloadResponse::for($request);
		stream_set_blocking($stream, true);
		stream_set_timeout($stream, self::WRITE_TIMEOUT);

		if ($response->send($stream) && $response->events) {
			stream_set_blocking($stream, false);
			$this->clients[(int) $stream] = $stream;
		} else {
			fclose($stream);
		}
	}

	/** @param resource $server */
	private function accept(mixed $server): void
	{
		/** @var resource|false $stream */
		$stream = ErrorTrap::run(static fn(): mixed => stream_socket_accept($server, 0));

		if ($stream === false) {
			return;
		}

		// Reads must never block the relay loop.
		stream_set_blocking($stream, false);
		$this->requests[(int) $stream] = ['stream' => $stream, 'buffer' => ''];
	}

	/** @param resource $stream */
	private function read(int $id, mixed $stream): void
	{
		$chunk = fread($stream, self::MAX_REQUEST);
		$closed = $chunk === false || $chunk === '' && feof($stream);
		$buffer = $this->requests[$id]['buffer'] . (string) $chunk;

		if (str_contains($buffer, "\r\n\r\n")) {
			unset($this->requests[$id]);
			$this->respond($stream, $buffer);

			return;
		}

		if ($closed || strlen($buffer) > self::MAX_REQUEST) {
			// Disconnected mid-request, or oversized.
			unset($this->requests[$id]);
			fclose($stream);

			return;
		}

		$this->requests[$id]['buffer'] = $buffer;
	}
}
