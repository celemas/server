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
	/**
	 * @param resource $process
	 * @param array<int, closed-resource|resource> $pipes
	 */
	private function __construct(
		private mixed $process,
		private array $pipes,
	) {}

	/**
	 * @param list<string> $command
	 * @param array<string, string>|null $environment
	 */
	public static function start(array $command, ?array $environment = null): ?self
	{
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

		if (isset($pipes[0])) {
			fclose($pipes[0]);
		}

		unset($pipes[0]);

		/** @var array<int, resource> $pipes */
		return new self($process, $pipes);
	}

	/**
	 * Runs a short command, like a version query, and returns its trimmed
	 * standard output. Returns null when it cannot start, prints nothing,
	 * or does not finish within the timeout.
	 *
	 * @param list<string> $command
	 */
	public static function output(array $command, float $timeout = 2.0): ?string
	{
		$process = self::start($command);

		if ($process === null) {
			return null;
		}

		$output = ['', ''];
		$deadline = (int) hrtime(true) + (int) ($timeout * 1e9);
		/** @var array<int, resource> $pipes */
		$pipes = array_filter([$process->pipe(1), $process->pipe(2)], is_resource(...));

		// Reads stderr too, so a chatty command never blocks on a full pipe.
		while ($pipes !== [] && hrtime(true) < $deadline) {
			$read = array_values($pipes);
			$write = null;
			$except = null;

			if (ErrorTrap::run(static fn(): mixed => stream_select($read, $write, $except, 0, 50_000)) === false) {
				break;
			}

			foreach ($read as $pipe) {
				$index = (int) array_search($pipe, $pipes, true);
				$output[$index] .= (string) fread($pipe, 8192);

				if (feof($pipe)) {
					unset($pipes[$index]);
				}
			}
		}

		$finished = $pipes === [];
		$process->close(terminate: !$finished);
		$stdout = trim($output[0]);

		return $finished && $stdout !== '' ? $stdout : null;
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
		return proc_get_status($this->process)['running'];
	}

	public function close(bool $terminate = false): int
	{
		foreach ($this->pipes as $pipe) {
			if (!is_resource($pipe)) {
				continue;
			}

			fclose($pipe);
		}

		if ($terminate) {
			ErrorTrap::run(fn(): mixed => proc_terminate($this->process));
		}

		return proc_close($this->process);
	}
}
