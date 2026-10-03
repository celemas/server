<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * Shared serve/watch orchestration for the dev-server backends; the
 * subclasses provide the backend process.
 *
 * @internal
 */
abstract class Runtime
{
	private readonly ReloadLog $log;

	/** @param array<string, list<string>|string> $companions */
	public function __construct(
		protected readonly Setup $setup,
		protected readonly Options $options,
		protected readonly Io $io,
		private readonly array $companions = [],
	) {
		$this->log = new ReloadLog($io, $options->quiet, $options->watchFiles);
	}

	/**
	 * Runs the backend, and the companions alongside it, until the backend
	 * stops. In watch mode, the live reload endpoint runs in this process.
	 *
	 * @param callable(string): void $output
	 */
	public function run(callable $output): string|int
	{
		$message = $this->missing() ?? Ports::unavailableMessage(
			$this->options->host,
			$this->options->port,
		);

		if ($message !== null) {
			return $message;
		}

		$reloadPort = $this->options->watch ? $this->reloadPort() : null;

		if (is_string($reloadPort)) {
			return $reloadPort;
		}

		$interrupt = Interrupt::catch();
		$backend = null;
		$companions = [];
		$liveReload = null;

		try {
			$script = $reloadPort === null ? null : ReloadResponse::url($this->options->host, $reloadPort);
			$backend = $this->start($this->options->port, $script);

			if (is_string($backend)) {
				return $backend;
			}

			$companions = $this->companions();

			// Only now: the processes would inherit the listening sockets,
			// and keep the port taken when they outlive this command.
			if ($reloadPort !== null) {
				$liveReload = $this->listen($reloadPort);

				if (is_string($liveReload)) {
					return $liveReload;
				}
			}

			$this->serving();

			if ($liveReload !== null) {
				$this->log->announce($liveReload);
			}

			$this->started();

			if ($this->options->open) {
				$this->openBrowser();
			}

			Relay::run([$backend->binding([1 => $output, 2 => $output])], $liveReload, $interrupt, $companions);
			$exitCode = $backend->close(terminate: $interrupt->received());

			return $interrupt->exitCode() ?? self::normalizeExitCode($exitCode);
		} finally {
			foreach ($companions as $companion) {
				$companion->stop();
			}

			// Stops a backend left running by an error; a closed one stays as it is.
			if ($backend instanceof Process) {
				$backend->stop();
			}

			$interrupt->release();

			if ($liveReload instanceof LiveReload) {
				$liveReload->close();
			}

			$this->cleanup();
		}
	}

	/**
	 * Starts the backend on the given port, or returns an error message.
	 * The live reload script URL is passed on as CELEMA_LIVE_RELOAD.
	 */
	abstract protected function start(int $port, ?string $liveReload): Process|string;

	protected function missing(): ?string
	{
		return null;
	}

	/** Backend details for the startup line, such as its version. */
	protected function details(): string
	{
		return '';
	}

	protected function started(): void {}

	/**
	 * Runs once watched files changed, before pages are told to reload.
	 * Work that takes a while is returned instead of waited for, so the
	 * backend's output is still relayed; pages reload once it is finished.
	 *
	 * @param list<string> $files
	 */
	protected function reloading(string $event, array $files): ?Pending
	{
		return null;
	}

	protected function cleanup(): void {}

	private function openBrowser(): void
	{
		$error = Browser::open($this->options->host, $this->options->port);

		if ($error !== null) {
			$this->io->warn($error);
		}
	}

	/** @return list<Companion> */
	private function companions(): array
	{
		if (!$this->options->companions) {
			return [];
		}

		$started = [];

		foreach ($this->companions as $name => $command) {
			$companion = Companion::start($name, $command, $this->io);

			if ($companion !== null) {
				$started[] = $companion;
			}
		}

		return $started;
	}

	private function reloadPort(): int|string
	{
		$port = $this->options->reloadPort ?? Ports::liveReloadPort($this->options->host, $this->options->port);

		if ($port === $this->options->port) {
			return 'The live reload port must differ from the server port.';
		}

		return $port;
	}

	private function listen(int $port): LiveReload|string
	{
		return LiveReload::listen(
			$this->options->host,
			$port,
			$this->options->watchFiles,
			$this->log->changed(...),
			$this->reloading(...),
		);
	}

	private function serving(): void
	{
		$url = Address::url($this->options->host, $this->options->port);
		$details = $this->details();
		$details = $details === '' ? '' : ' <dim>(' . $this->io->escape($details) . ')</dim>';
		$this->io->echoln("Serving {$url}{$details}");
	}

	private static function normalizeExitCode(int $exitCode): int
	{
		return $exitCode < 0 ? 1 : $exitCode;
	}
}
