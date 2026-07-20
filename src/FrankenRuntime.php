<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Io;

/** @internal */
final readonly class FrankenRuntime
{
	public function __construct(
		private Setup $setup,
		private FrankenOptions $options,
		private Io $io,
	) {}

	public function serve(callable $output): string|int
	{
		if ($this->setup->missingFrankenPhp()) {
			return 'FrankenPHP requires frankenphp in PATH.';
		}

		$message = $this->setup->portUnavailableMessage($this->options->host, $this->options->port);

		if ($message !== null) {
			return $message;
		}

		$frankenPhp = $this->start($this->options->port);

		if ($frankenPhp === null) {
			return 'Failed to start FrankenPHP.';
		}

		Relay::run([$frankenPhp->binding([1 => $output, 2 => $output])]);

		return $this->normalizeExitCode($frankenPhp->close());
	}

	public function watch(callable $output, callable $browserOutput): string|int
	{
		if ($this->setup->missingFrankenPhp()) {
			return 'FrankenPHP requires frankenphp in PATH.';
		}

		$backendPort = Setup::backendPort($this->options->port);
		$missing = $this->setup->missingBrowserSyncDependencies();

		if ($missing !== []) {
			return 'BrowserSync requires ' . implode(' and ', $missing) . ' in PATH.';
		}

		$message = $this->setup->portUnavailableMessage($this->options->host, $this->options->port);

		if ($message !== null) {
			return $message;
		}

		$message = $this->setup->portUnavailableMessage($this->options->host, $backendPort);

		if ($message !== null) {
			return $message;
		}

		$frankenPhp = $this->start($backendPort);

		if ($frankenPhp === null) {
			return 'Failed to start FrankenPHP.';
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
			$frankenPhp->close(terminate: true);

			return 'Failed to start BrowserSync.';
		}

		$this->io->echoln(
			"BrowserSync proxy listening on http://{$this->options->host}:{$this->options->port}",
		);
		$this->io->echoln(
			"FrankenPHP listening on http://{$this->options->host}:{$backendPort}",
		);

		Relay::run([
			$frankenPhp->binding([1 => $output, 2 => $output]),
			$browserSync->binding([1 => $browserOutput, 2 => $browserOutput]),
		]);

		$frankenPhpStopped = !$frankenPhp->running();
		$browserSyncStopped = !$browserSync->running();
		$frankenPhpExit = $frankenPhp->close(terminate: !$frankenPhpStopped);
		$browserSyncExit = $browserSync->close(terminate: !$browserSyncStopped);

		if ($frankenPhpStopped && $frankenPhpExit !== 0) {
			return $this->normalizeExitCode($frankenPhpExit);
		}

		if ($browserSyncStopped && $browserSyncExit !== 0) {
			return $this->normalizeExitCode($browserSyncExit);
		}

		return 0;
	}

	private function start(int $port): ?Process
	{
		return Process::start(
			$this->setup->frankenPhpCommand($this->options->host, $port, $this->options->debug),
			$this->setup->frankenPhpEnvironment(),
		);
	}

	private function normalizeExitCode(int $exitCode): int
	{
		return $exitCode < 0 ? 1 : $exitCode;
	}
}
