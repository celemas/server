<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Turns SIGINT and SIGTERM into a flag the relay loop checks, so the
 * command stops its processes and removes its temporary files instead
 * of dying at once. A second signal stops the command right away.
 *
 * Ctrl+C in a terminal reaches the whole process group, so the backend
 * stops by itself; a signal sent to the command alone reaches only the
 * command, which then terminates the backend.
 *
 * Without the pcntl extension, signals keep their default behavior.
 * Releasing restores the default handlers; the commands run as the
 * main program, which installs no handlers of its own.
 *
 * @internal
 */
final class Interrupt
{
	private ?int $signal = null;
	private bool $async = false;
	private bool $caught = false;

	private function __construct() {}

	public static function catch(): self
	{
		$interrupt = new self();

		if (!function_exists('pcntl_signal')) {
			return $interrupt;
		}

		$interrupt->async = pcntl_async_signals(true);
		$interrupt->caught = true;
		pcntl_signal(SIGINT, $interrupt->handle(...));
		pcntl_signal(SIGTERM, $interrupt->handle(...));

		return $interrupt;
	}

	public function received(): bool
	{
		return $this->signal !== null;
	}

	/** The exit code of a command stopped by the signal, as shells report it. */
	public function exitCode(): ?int
	{
		return $this->signal === null ? null : 128 + $this->signal;
	}

	public function release(): void
	{
		if (!$this->caught) {
			return;
		}

		pcntl_signal(SIGINT, SIG_DFL);
		pcntl_signal(SIGTERM, SIG_DFL);
		pcntl_async_signals($this->async);
		$this->caught = false;
	}

	private function handle(int $signal): void
	{
		if ($this->signal === null) {
			$this->signal = $signal;

			return;
		}

		// A second signal: the default handler ends the command at once.
		$this->release();

		if (function_exists('posix_kill')) {
			posix_kill(posix_getpid(), $signal);
		}

		exit(128 + $signal);
	}
}
