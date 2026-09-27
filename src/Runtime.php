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

		try {
			$backend = $this->start($this->options->port, $liveReload?->script);

			if (is_string($backend)) {
				return $backend;
			}

			if ($liveReload !== null) {
				$this->io->echoln("Live reload script: {$liveReload->script}");
			}

			$this->started();
			Relay::run([$backend->binding([1 => $output, 2 => $output])], $liveReload);

			return self::normalizeExitCode($backend->close());
		} finally {
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

	protected function started(): void {}

	protected function cleanup(): void {}

	private function liveReload(): LiveReload|string
	{
		$port = Ports::liveReloadPort($this->options->host, $this->options->port);

		if (is_string($port)) {
			return $port;
		}

		return LiveReload::listen($this->options->host, $port, $this->options->watchFiles, $this->changed(...));
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

		$action = $event === 'css' ? 'restyle' : 'reload';
		$pages = $clients === 1 ? '1 page' : "{$clients} pages";
		$this->io->echoln("{$timestamp} <magenta>{$action}</magenta> {$file} <dim>· {$pages}</dim>");
	}

	private static function normalizeExitCode(int $exitCode): int
	{
		return $exitCode < 0 ? 1 : $exitCode;
	}
}
