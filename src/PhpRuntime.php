<?php

declare(strict_types=1);

namespace Celema\Core\Server;

/** @internal */
final class PhpRuntime extends Runtime
{
	protected function start(int $port): Process|string
	{
		$php = Process::start(
			$this->setup->phpCommand($this->options->host, $port, $this->options->quiet),
			$this->setup->phpEnvironment($this->options->debug),
		);

		return $php ?? 'Failed to start the PHP server.';
	}

	protected function label(): string
	{
		return 'PHP server';
	}

	protected function started(): void
	{
		if ($this->options->debug) {
			$this->io->echoln('<red>Xdebug session enabled</red>');
		}
	}
}
