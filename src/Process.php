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
