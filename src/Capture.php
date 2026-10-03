<?php

declare(strict_types=1);

namespace Celema\Server;

/** @internal */
final class Capture
{
	/**
	 * Runs a short command, like a version query, and returns its trimmed
	 * standard output. Returns null when it cannot start, prints nothing,
	 * or does not finish within the timeout.
	 *
	 * @param list<string> $command
	 * @param array<string, string>|null $environment
	 */
	public static function output(array $command, float $timeout = 2.0, ?array $environment = null): ?string
	{
		$process = Process::start($command, $environment);

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
}
