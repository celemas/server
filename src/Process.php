<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * @internal
 *
 * @psalm-type Binding = array{process: Process, handlers: array<int, callable(string): void>}
 */
final class Process
{
	private const int SIGTERM = 15;
	private const int SIGKILL = 9;

	private ?int $exitCode = null;
	private readonly int $pid;

	/**
	 * @param resource $process
	 * @param array<int, closed-resource|resource> $pipes
	 */
	private function __construct(
		private mixed $process,
		private array $pipes,
		private readonly bool $group,
	) {
		$this->pid = proc_get_status($process)['pid'];
	}

	/**
	 * Starts a command, given as arguments or as a shell command line.
	 *
	 * In its own process `group`, the command is stopped together with
	 * every process it starts. Its input stays open with `keepInput`, as
	 * watchers like esbuild's stop when it closes; it closes when this
	 * process ends.
	 *
	 * @param list<string>|string $command
	 * @param array<string, string>|null $environment
	 */
	public static function start(
		array|string $command,
		?array $environment = null,
		bool $group = false,
		bool $keepInput = false,
	): ?self {
		$group = $group && ProcessGroup::available();

		if ($group) {
			$command = ProcessGroup::command($command);

			if ($command === null) {
				return null;
			}
		}

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$pipes = [];
		$process = proc_open($command, $descriptors, $pipes, env_vars: $environment);

		if (!is_resource($process)) {
			return null;
		}

		if (!$keepInput) {
			fclose($pipes[0]);
			unset($pipes[0]);
		}

		/** @var array<int, resource> $pipes */
		return new self($process, $pipes, $group);
	}

	/**
	 * @param array<int, callable(string): void> $handlers
	 *
	 * @return Binding
	 */
	public function binding(array $handlers): array
	{
		return [
			'process' => $this,
			'handlers' => $handlers,
		];
	}

	/** @return closed-resource|resource|null */
	public function pipe(int $index): mixed
	{
		return $this->pipes[$index] ?? null;
	}

	public function running(): bool
	{
		return $this->exitCode === null && proc_get_status($this->process)['running'];
	}

	/** Closes the pipes and waits for the process to end, or stops it first. */
	public function close(bool $terminate = false): int
	{
		if ($terminate) {
			return $this->stop();
		}

		if ($this->exitCode !== null) {
			return $this->exitCode;
		}

		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}

		return $this->exitCode = proc_close($this->process);
	}

	/**
	 * Stops the process, and in its own group every process it started:
	 * SIGTERM first, SIGKILL for whatever is left after the grace period.
	 * Returns the exit code.
	 */
	public function stop(float $grace = 5.0): int
	{
		if ($this->exitCode !== null) {
			return $this->exitCode;
		}

		$this->signal(self::SIGTERM);
		$deadline = (int) hrtime(true) + (int) ($grace * 1e9);

		while ($this->running() && hrtime(true) < $deadline) {
			usleep(20_000);
		}

		// Group members may outlive the leader, like the forked processes
		// of a PHP server whose main process was stopped.
		if ($this->group || $this->running()) {
			$this->signal(self::SIGKILL);
		}

		return $this->close();
	}

	private function signal(int $signal): void
	{
		// Right after the start, the group may not exist yet.
		if ($this->group && ProcessGroup::signal($this->pid, $signal)) {
			return;
		}

		ErrorTrap::run(fn(): mixed => proc_terminate($this->process, $signal));
	}
}
