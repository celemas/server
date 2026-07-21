<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/**
 * Shared serve/watch orchestration for the dev-server backends; the
 * subclasses provide the backend process and its startup messages.
 *
 * @internal
 */
abstract class Runtime
{
	public function __construct(
		protected readonly Setup $setup,
		protected readonly Options $options,
		protected readonly Io $io,
	) {}

	public function serve(callable $output): string|int
	{
		$message = $this->missing() ?? $this->setup->portUnavailableMessage(
			$this->options->host,
			$this->options->port,
		);

		if ($message !== null) {
			return $message;
		}

		try {
			$backend = $this->start($this->options->port);

			if (is_string($backend)) {
				return $backend;
			}

			$this->started();
			Relay::run([$backend->binding([1 => $output, 2 => $output])]);

			return self::normalizeExitCode($backend->close());
		} finally {
			$this->cleanup();
		}
	}

	public function watch(callable $output, callable $browserOutput): string|int
	{
		$backendPort = Setup::backendPort($this->options->port);
		$message =
			$this->missing() ?? $this->missingBrowserSync() ?? $this->setup->portUnavailableMessage(
				$this->options->host,
				$this->options->port,
			) ?? $this->setup->portUnavailableMessage($this->options->host, $backendPort);

		if ($message !== null) {
			return $message;
		}

		try {
			$backend = $this->start($backendPort);

			if (is_string($backend)) {
				return $backend;
			}

			$browserSync = Process::start(
				$this->setup->browserSyncCommand(
					$this->options->host,
					$this->options->port,
					$backendPort,
					$this->options->quiet,
				),
			);

			if ($browserSync === null) {
				$backend->close(terminate: true);

				return 'Failed to start BrowserSync.';
			}

			$this->io->echoln(
				"BrowserSync proxy listening on http://{$this->options->host}:{$this->options->port}",
			);
			$this->io->echoln(
				"{$this->label()} listening on http://{$this->options->host}:{$backendPort}",
			);
			$this->started();

			Relay::run([
				$backend->binding([1 => $output, 2 => $output]),
				$browserSync->binding([1 => $browserOutput, 2 => $browserOutput]),
			]);

			return $this->resolve($backend, $browserSync);
		} finally {
			$this->cleanup();
		}
	}

	/** Starts the backend on the given port, or returns an error message. */
	abstract protected function start(int $port): Process|string;

	abstract protected function label(): string;

	protected function missing(): ?string
	{
		return null;
	}

	protected function started(): void {}

	protected function cleanup(): void {}

	private function missingBrowserSync(): ?string
	{
		$missing = $this->setup->missingBrowserSyncDependencies();

		if ($missing === []) {
			return null;
		}

		return 'BrowserSync requires ' . implode(' and ', $missing) . ' in PATH.';
	}

	private function resolve(Process $backend, Process $browserSync): int
	{
		$backendStopped = !$backend->running();
		$browserSyncStopped = !$browserSync->running();
		$backendExit = $backend->close(terminate: !$backendStopped);
		$browserSyncExit = $browserSync->close(terminate: !$browserSyncStopped);

		if ($backendStopped && $backendExit !== 0) {
			return self::normalizeExitCode($backendExit);
		}

		if ($browserSyncStopped && $browserSyncExit !== 0) {
			return self::normalizeExitCode($browserSyncExit);
		}

		return 0;
	}

	private static function normalizeExitCode(int $exitCode): int
	{
		return $exitCode < 0 ? 1 : $exitCode;
	}
}
