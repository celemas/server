<?php

declare(strict_types=1);

namespace Celema\Server;

use Override;

/** @internal */
final class PhpRuntime extends Runtime
{
	#[Override]
	protected function start(int $port, ?string $liveReload): Process|string
	{
		$this->loadIni();

		$php = Process::start(
			$this->setup->phpCommand($this->options->host, $port, $this->options->quiet),
			$this->setup->phpEnvironment(
				$this->options->debug,
				$liveReload,
				$this->options->processes,
				$this->ini?->dir,
			),
			group: true,
		);

		return $php ?? 'Failed to start the PHP server.';
	}

	#[Override]
	protected function missing(): ?string
	{
		// The PHP server forks its processes, which Windows cannot do.
		if (DIRECTORY_SEPARATOR === '\\' && ($this->options->processes ?? 1) > 1) {
			return 'The PHP server cannot run multiple processes on Windows.';
		}

		return null;
	}

	#[Override]
	protected function details(): string
	{
		$version = Capture::output($this->setup->phpVersionCommand());

		return $version !== null && preg_match('/^\d+\.\d+\.\d+\S*$/D', $version) === 1 ? "PHP {$version}" : '';
	}

	#[Override]
	protected function started(): void
	{
		if ($this->options->debug) {
			$this->io->line('<red>Xdebug session enabled</red>');
		}
	}
}
