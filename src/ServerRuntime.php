<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final readonly class ServerRuntime
{
	public function __construct(
		private ServerSupport $support,
		private ServerOptions $options,
	) {}

	public function serve(callable $phpOutput): string|int
	{
		$message = $this->support->portUnavailableMessage($this->options->host, $this->options->port);

		if ($message !== null) {
			return $message;
		}

		$php = ServerProcess::start(
			$this->support->phpCommand($this->options->host, $this->options->port, $this->options->quiet),
			$this->support->phpEnvironment($this->options->debugger),
		);

		if ($php === null) {
			return 'Failed to start the PHP server.';
		}

		$this->echoDebugger();
		ServerRelay::run([$php->binding([1 => $phpOutput, 2 => $phpOutput])]);

		return $this->normalizeExitCode($php->close());
	}

	public function watch(callable $phpOutput, callable $browserOutput): string|int
	{
		$backendPort = ServerSupport::backendPort($this->options->port);
		$missing = $this->support->missingBrowserSyncDependencies();

		if ($missing !== []) {
			return 'BrowserSync requires ' . implode(' and ', $missing) . ' in PATH.';
		}

		$message = $this->support->portUnavailableMessage($this->options->host, $this->options->port);

		if ($message !== null) {
			return $message;
		}

		$message = $this->support->portUnavailableMessage($this->options->host, $backendPort);

		if ($message !== null) {
			return $message;
		}

		$php = ServerProcess::start(
			$this->support->phpCommand($this->options->host, $backendPort, $this->options->quiet),
			$this->support->phpEnvironment($this->options->debugger),
		);

		if ($php === null) {
			return 'Failed to start the PHP server.';
		}

		$browserSync = ServerProcess::start(
			$this->support->browserSyncCommand(
				$this->options->host,
				$this->options->port,
				$backendPort,
				$this->options->quiet,
			),
		);

		if ($browserSync === null) {
			$php->close(terminate: true);

			return 'Failed to start BrowserSync.';
		}

		echo "BrowserSync proxy listening on http://{$this->options->host}:{$this->options->port}\n";
		echo "PHP server listening on http://{$this->options->host}:{$backendPort}\n";
		$this->echoDebugger();

		ServerRelay::run([
			$php->binding([1 => $phpOutput, 2 => $phpOutput]),
			$browserSync->binding([1 => $browserOutput, 2 => $browserOutput]),
		]);

		$phpStopped = !$php->running();
		$browserSyncStopped = !$browserSync->running();
		$phpExit = $php->close(terminate: !$phpStopped);
		$browserSyncExit = $browserSync->close(terminate: !$browserSyncStopped);

		if ($phpStopped && $phpExit !== 0) {
			return $this->normalizeExitCode($phpExit);
		}

		if ($browserSyncStopped && $browserSyncExit !== 0) {
			return $this->normalizeExitCode($browserSyncExit);
		}

		return 0;
	}

	private function echoDebugger(): void
	{
		if ($this->options->debugger) {
			echo "\033[0;31mXdebug session enabled\033[0m\n";
		}
	}

	private function normalizeExitCode(int $exitCode): int
	{
		return $exitCode < 0 ? 1 : $exitCode;
	}
}
