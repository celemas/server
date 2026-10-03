<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;
use Override;

/**
 * Live reload without a backend, for an application served elsewhere,
 * like in a container. The endpoint listens on the fixed port the
 * application's pages load the script from. With the address of
 * FrankenPHP's admin API, changes restart its workers.
 *
 * @internal
 */
final class ReloadRuntime extends Runtime
{
	/**
	 * @param ?array{host: string, port: int} $admin
	 * @param array<string, list<string>|string> $companions
	 */
	public function __construct(
		Options $options,
		Io $io,
		private readonly ?array $admin = null,
		array $companions = [],
	) {
		parent::__construct(new Setup('', ''), $options, $io, $companions);
	}

	#[Override]
	protected function start(int $port, ?string $liveReload): null
	{
		return null;
	}

	/** The port pages load the script from: a fallback would leave them without it. */
	#[Override]
	protected function reloadPort(): int
	{
		return $this->options->port;
	}

	#[Override]
	protected function serving(?LiveReload $liveReload): void
	{
		$this->io->echoln('Live reload for an application served elsewhere, which needs this environment variable:');
		$this->io->echoln('CELEMA_LIVE_RELOAD=' . (string) $liveReload?->script);
	}

	#[Override]
	protected function started(): void
	{
		if ($this->admin === null) {
			return;
		}

		$address = "tcp://{$this->admin['host']}:{$this->admin['port']}";
		/** @var resource|false $socket */
		$socket = ErrorTrap::run(static fn(): mixed => stream_socket_client($address, timeout: 1));

		if ($socket === false) {
			$this->io->warn("The FrankenPHP admin API at {$address} does not answer; workers will not restart.");

			return;
		}

		fclose($socket);
	}

	#[Override]
	protected function reloading(string $event, array $files): ?Pending
	{
		if ($this->admin === null || !WorkerRestart::needed($files)) {
			return null;
		}

		return WorkerRestart::send($this->admin['host'], $this->admin['port'], $this->log->restarted(...));
	}
}
