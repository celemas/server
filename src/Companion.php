<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;
use InvalidArgumentException;

/**
 * A process that runs alongside the server, like an asset watcher. Its
 * output appears with its name. When it exits, the server keeps running;
 * it stops together with the server.
 *
 * @internal
 *
 * @psalm-import-type Binding from Process
 */
final class Companion
{
	private bool $reported = false;

	private function __construct(
		private readonly string $name,
		private readonly Process $process,
		private readonly Io $io,
	) {}

	/**
	 * Validates the configured companions: names, each with a command
	 * line or a list of arguments.
	 *
	 * @return array<string, list<string>|string>
	 */
	public static function validate(array $companions): array
	{
		$valid = [];

		/** @var mixed $command */
		foreach ($companions as $name => $command) {
			if (!is_string($name) || trim($name) === '') {
				throw new InvalidArgumentException(
					"Companion processes need names, like ['css' => 'npx @tailwindcss/cli -i app.css -o public/app.css --watch'].",
				);
			}

			if (is_string($command) && trim($command) !== '') {
				$valid[$name] = $command;
			} elseif (
				is_array($command)
				&& $command !== []
				&& array_is_list($command)
				&& array_all($command, static fn(mixed $argument): bool => is_string($argument))
			) {
				/** @var list<string> $command */
				$valid[$name] = $command;
			} else {
				throw new InvalidArgumentException(
					"The companion process '{$name}' needs a command line or a list of arguments.",
				);
			}
		}

		return $valid;
	}

	/**
	 * Starts the companion in its own process group, which also stops
	 * the processes it starts itself, like the one `npx` runs.
	 *
	 * @param list<string>|string $command
	 */
	public static function start(string $name, array|string $command, Io $io): ?self
	{
		$process = Process::start($command, group: true, keepInput: true);

		if ($process === null) {
			$io->warn("Could not start the companion process '{$name}'.");

			return null;
		}

		return new self($name, $process, $io);
	}

	/** @return Binding */
	public function binding(): array
	{
		return $this->process->binding([1 => $this->line(...), 2 => $this->line(...)]);
	}

	/**
	 * Shows a line of output with the companion's name. Of a line that
	 * redraws itself, like a progress bar, only the last state shows;
	 * colors are removed, as they would clash with the server's.
	 */
	public function line(string $line): void
	{
		$line = (string) preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e\][^\a\e]*(?:\a|\e\\\\)/', '', $line);
		$parts = array_filter(explode("\r", rtrim($line, "\r\n")), static fn(string $part): bool => trim($part) !== '');
		$text = end($parts);

		if ($text === false) {
			return;
		}

		$this->io->echoln('<cyan>' . $this->io->escape($this->name) . '</cyan> ' . $this->io->escape($text));
	}

	/**
	 * Reports once that the companion exited, after its output: once its
	 * pipes are closed.
	 */
	public function check(): void
	{
		if ($this->reported || $this->process->running()) {
			return;
		}

		foreach ([1, 2] as $index) {
			if (is_resource($this->process->pipe($index))) {
				return;
			}
		}

		$this->reported = true;
		$code = $this->process->close();
		$timestamp = '<dim>' . RequestOutput::timestamp() . '</dim>';
		$this->io->echoln(
			"{$timestamp} <cyan>"
				. $this->io->escape($this->name)
				. "</cyan> <yellow>exited with code {$code}</yellow>",
		);
	}

	public function stop(): void
	{
		$this->process->stop();
	}
}
