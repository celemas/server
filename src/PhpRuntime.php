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
		$php = Process::start(
			$this->setup->phpCommand($this->options->host, $port, $this->options->quiet),
			$this->setup->phpEnvironment($this->options->debug, $liveReload),
		);

		return $php ?? 'Failed to start the PHP server.';
	}

	#[Override]
	protected function started(): void
	{
		if ($this->options->debug) {
			$this->io->echoln('<red>Xdebug session enabled</red>');
		}
	}
}
