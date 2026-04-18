<?php

declare(strict_types=1);

namespace Duon\Core\Server;

/** @internal */
final class Process
{
	private function __construct(
		private mixed $process,
		private array $pipes,
	) {}

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

		if (isset($pipes[0]) && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}

		unset($pipes[0]);

		return new self($process, $pipes);
	}

	public function binding(array $handlers): array
	{
		return [
			'process' => $this,
			'handlers' => $handlers,
		];
	}

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
