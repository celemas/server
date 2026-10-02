<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Watch mode: polls the watched files and tells the pages connected to
 * the reload endpoint to reload when they change. Pages opt in by
 * including the endpoint's script, whose URL the backend gets as
 * CELEMA_LIVE_RELOAD.
 *
 * @internal
 *
 * @psalm-type ChangeLog = callable(string, list<string>, int): void
 */
final class LiveReload
{
	private const int INTERVAL = 200_000_000;

	/** @var list<string> */
	private array $changed = [];

	private int $scanned;

	/**
	 * @param ChangeLog $log
	 * @param ?callable(string, list<string>): void $beforeReload
	 */
	private function __construct(
		private readonly ReloadEndpoint $endpoint,
		public readonly string $script,
		private readonly FileWatch $files,
		private readonly mixed $log,
		private readonly mixed $beforeReload = null,
	) {
		$this->scanned = hrtime(true);
	}

	/**
	 * @param list<string> $patterns
	 * @param ChangeLog $log Receives the event, the changed files, and the number of notified pages
	 * @param ?callable(string, list<string>): void $beforeReload Runs before pages are notified,
	 *     for example to restart a worker that has to serve the reloaded pages
	 */
	public static function listen(
		string $host,
		int $port,
		array $patterns,
		callable $log,
		?callable $beforeReload = null,
	): self|string {
		$endpoint = ReloadEndpoint::listen($host, $port);

		if (is_string($endpoint)) {
			return $endpoint;
		}

		return new self($endpoint, ReloadResponse::url($host, $port), new FileWatch($patterns), $log, $beforeReload);
	}

	public function watched(): int
	{
		return $this->files->count();
	}

	/** @return list<resource> */
	public function streams(): array
	{
		return $this->endpoint->streams();
	}

	/** @param list<resource> $ready */
	public function handle(array $ready): void
	{
		$this->endpoint->handle($ready);
	}

	/**
	 * Scans the watched files, at most once per interval. Pages are
	 * notified once a scan finds no further changes, so a burst of
	 * writes, like a formatter running after a save, triggers a single
	 * reload.
	 */
	public function poll(): void
	{
		$now = hrtime(true);

		if (($now - $this->scanned) < self::INTERVAL) {
			return;
		}

		$this->scanned = $now;
		$changes = $this->files->changes();

		if ($changes !== []) {
			$this->changed = array_values(array_unique([...$this->changed, ...$changes]));

			return;
		}

		if ($this->changed === []) {
			return;
		}

		$event = self::event($this->changed);

		if ($this->beforeReload !== null) {
			($this->beforeReload)($event, $this->changed);
		}

		($this->log)($event, $this->changed, $this->endpoint->broadcast($event));
		$this->changed = [];
	}

	public function close(): void
	{
		$this->endpoint->close();
	}

	/**
	 * Stylesheets can be swapped in place; everything else reloads the page.
	 *
	 * @param list<string> $changed
	 */
	private static function event(array $changed): string
	{
		foreach ($changed as $path) {
			if (!str_ends_with(strtolower($path), '.css')) {
				return 'reload';
			}
		}

		return 'css';
	}
}
