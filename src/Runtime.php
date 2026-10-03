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
	public function __construct(
		protected readonly Setup $setup,
		protected readonly Options $options,
		protected readonly Io $io,
	) {}

	/**
	 * Runs the backend until it stops. In watch mode, the live reload
	 * endpoint runs alongside it in this process.
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

		$liveReload = $this->options->watch ? $this->liveReload() : null;

		if (is_string($liveReload)) {
			return $liveReload;
		}

		$interrupt = Interrupt::catch();

		try {
			$backend = $this->start($this->options->port, $liveReload?->script);

			if (is_string($backend)) {
				return $backend;
			}

			$this->serving();

			if ($liveReload !== null) {
				$this->announce($liveReload);
			}

			$this->started();

			if ($this->options->open) {
				$this->openBrowser();
			}

			Relay::run([$backend->binding([1 => $output, 2 => $output])], $liveReload, $interrupt);
			$exitCode = $backend->close(terminate: $interrupt->received());

			return $interrupt->exitCode() ?? self::normalizeExitCode($exitCode);
		} finally {
			$interrupt->release();
			$liveReload?->close();
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

	private function liveReload(): LiveReload|string
	{
		$port = $this->options->reloadPort ?? Ports::liveReloadPort($this->options->host, $this->options->port);

		if (is_string($port)) {
			return $port;
		}

		if ($port === $this->options->port) {
			return 'The live reload port must differ from the server port.';
		}

		return LiveReload::listen(
			$this->options->host,
			$port,
			$this->options->watchFiles,
			$this->changed(...),
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

	private function announce(LiveReload $liveReload): void
	{
		$this->io->echoln("Live reload script: {$liveReload->script}");
		$watched = $liveReload->watched();

		if ($watched > 0) {
			$this->io->echoln('<dim>Watching ' . ($watched === 1 ? '1 file' : "{$watched} files") . '</dim>');

			return;
		}

		// A typo in a pattern would otherwise go unnoticed.
		$this->io->echoln(
			'<yellow>No files match the watch patterns:</yellow> '
				. $this->io->escape(implode(', ', $this->options->watchFiles)),
		);
	}

	/** @param list<string> $files */
	private function changed(string $event, array $files, int $clients): void
	{
		if ($this->options->quiet && $clients > 0) {
			return;
		}

		$more = count($files) > 1 ? ' <dim>(+' . (count($files) - 1) . ' more)</dim>' : '';
		$file = $this->io->escape($files[0] ?? '') . $more;
		$timestamp = '<dim>' . RequestOutput::timestamp() . '</dim>';

		if ($clients === 0) {
			$this->io->echoln(
				"{$timestamp} <yellow>changed</yellow> {$file} "
					. '<dim>· no page connected, include the live reload script</dim>',
			);

			return;
		}

		$action = $event === 'css' ? 'restyle' : $event;
		$pages = $clients === 1 ? '1 page' : "{$clients} pages";
		$this->io->echoln("{$timestamp} <magenta>{$action}</magenta> {$file} <dim>· {$pages}</dim>");
	}

	private static function normalizeExitCode(int $exitCode): int
	{
		return $exitCode < 0 ? 1 : $exitCode;
	}
}
