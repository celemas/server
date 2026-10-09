<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Runner;

final class Cli
{
	/**
	 * Runs the command as its command line with the given tokens would.
	 *
	 * @param list<string> $tokens
	 */
	public static function run(object $command, Io $io, array $tokens = []): int
	{
		return new Runner([$command], $io)->run(['cserve', Command::of($command)->full(), ...$tokens]);
	}
}
