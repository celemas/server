<?php

declare(strict_types=1);

namespace Celema\Server;

use Closure;
use Override;

/**
 * Restarts the FrankenPHP workers through the admin API, which the
 * generated configuration binds to a loopback port only; the reload
 * command may also reach the admin API of a FrankenPHP served elsewhere.
 *
 * The request does not wait for the answer: FrankenPHP logs while it
 * restarts, and that output has to be read meanwhile. Once the pipe is
 * full, FrankenPHP would block and the restart could not finish.
 *
 * FrankenPHP's own `watch` directive is not used: it does not follow the
 * symlinked package directories of path repositories.
 *
 * @internal
 */
final class WorkerRestart implements Pending
{
	private string $response = '';
	private bool $reported = false;
	private readonly int|float $deadline;

	/**
	 * @param ?resource $socket
	 * @param Closure(?string): void $done
	 */
	private function __construct(
		private mixed $socket,
		private ?string $error,
		private readonly Closure $done,
		private readonly int $timeout,
	) {
		$this->deadline = hrtime(true) + ($timeout * 1_000_000_000);
	}

	/**
	 * A worker keeps loaded code in memory; only stylesheets and scripts
	 * the browser loads itself are picked up without a restart.
	 *
	 * @param list<string> $files
	 */
	public static function needed(array $files): bool
	{
		return !LiveReload::browserOnly($files);
	}

	/**
	 * Sends the restart request. Advance it until it is finished.
	 *
	 * @param Closure(?string): void $done Receives an error message, or null once the workers restarted
	 */
	public static function send(string $host, int $port, Closure $done, int $timeout = 10): self
	{
		$address = "tcp://{$host}:{$port}";
		/** @var resource|false $socket */
		$socket = ErrorTrap::run(
			static fn(): mixed => stream_socket_client($address, timeout: $timeout),
			$error,
		);

		if ($socket === false) {
			return new self(
				null,
				'Could not restart the worker: ' . ($error ?? "{$address} is unreachable"),
				$done,
				$timeout,
			);
		}

		fwrite(
			$socket,
			"POST /frankenphp/workers/restart HTTP/1.1\r\n"
				. "Host: {$host}:{$port}\r\n"
				. "Content-Length: 0\r\n"
				. "Connection: close\r\n\r\n",
		);
		stream_set_blocking($socket, false);

		return new self($socket, null, $done, $timeout);
	}

	#[Override]
	public function streams(): array
	{
		return $this->socket === null ? [] : [$this->socket];
	}

	#[Override]
	public function advance(): bool
	{
		if ($this->socket !== null) {
			$this->read($this->socket);
		}

		if ($this->socket !== null) {
			if (hrtime(true) < $this->deadline) {
				return false;
			}

			$this->close();
			$this->error = "Could not restart the worker: no answer from the FrankenPHP admin API within {$this->timeout} s";
		}

		if (!$this->reported) {
			$this->reported = true;
			($this->done)($this->error ?? $this->outcome());
		}

		return true;
	}

	/** @param resource $socket */
	private function read(mixed $socket): void
	{
		while (($chunk = fread($socket, 8192)) !== false && $chunk !== '') {
			$this->response .= $chunk;
		}

		// The admin API closes the connection after its answer.
		if (feof($socket)) {
			$this->close();
		}
	}

	private function close(): void
	{
		$socket = $this->socket;
		$this->socket = null;

		if ($socket !== null) {
			fclose($socket);
		}
	}

	private function outcome(): ?string
	{
		[$head, $body] = [...explode("\r\n\r\n", $this->response, 2), ''];
		$status = explode("\r\n", $head, 2)[0];

		if (preg_match('/^HTTP\/\S+ 2\d\d\b/', $status) === 1) {
			return null;
		}

		if ($status === '') {
			return 'Could not restart the worker: no answer from the FrankenPHP admin API';
		}

		return 'Could not restart the worker: ' . trim($status . ' ' . $body);
	}
}
