<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Watch mode: polls the watched files and tells the pages connected to
 * the reload endpoint to update when they change. Pages opt in by
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

	/** Work the next reload waits for. */
	private ?Pending $pending = null;

	/**
	 * @param ChangeLog $log
	 * @param ?callable(string, list<string>): ?Pending $beforeReload
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
	 * @param ?callable(string, list<string>): ?Pending $beforeReload Runs before pages are notified,
	 *     for example to restart a worker that has to serve the reloaded pages. Pages are notified
	 *     once the work it returns is finished.
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
		return [...$this->endpoint->streams(), ...($this->pending?->streams() ?? [])];
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
		if ($this->pending !== null) {
			// Changes made meanwhile are found by the next scan.
			if (!$this->pending->advance()) {
				return;
			}

			$this->pending = null;
			$this->notify();
		}

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

		$pending = $this->beforeReload === null
			? null
			: ($this->beforeReload)(self::event($this->changed), $this->changed);

		if ($pending !== null && !$pending->advance()) {
			$this->pending = $pending;

			return;
		}

		$this->notify();
	}

	public function close(): void
	{
		$this->endpoint->close();
	}

	private function notify(): void
	{
		$event = self::event($this->changed);
		// A morph keeps the stylesheets that are still linked; the page has
		// to swap them as well when they changed.
		$data = $event === 'morph' && self::has($this->changed, ['css']) ? 'css' : '';
		($this->log)($event, $this->changed, $this->endpoint->broadcast($event, $data));
		$this->changed = [];
	}

	/**
	 * Stylesheets can be swapped in place. Pages morph into a freshly
	 * rendered copy when other files change, such as code or templates,
	 * which keeps the scroll position and form input. Scripts that already
	 * ran cannot be replaced, so they reload the page.
	 *
	 * @param list<string> $changed
	 */
	private static function event(array $changed): string
	{
		if (self::has($changed, ['js', 'mjs', 'cjs'])) {
			return 'reload';
		}

		foreach ($changed as $path) {
			if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'css') {
				return 'morph';
			}
		}

		return 'css';
	}

	/**
	 * @param list<string> $changed
	 * @param list<string> $extensions
	 */
	private static function has(array $changed, array $extensions): bool
	{
		foreach ($changed as $path) {
			if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
				return true;
			}
		}

		return false;
	}
}
