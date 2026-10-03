<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\BufferedIo;
use Celema\Console\Commands;
use Celema\Console\Runner;
use Celema\Server\FrankenPhp;
use Celema\Server\Ports;
use Celema\Server\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class ProcessesTest extends TestCase
{
	/** @param list<string> $args */
	#[DataProvider('processCounts')]
	public function testProcessCountReachesTheBackend(array $args, ?string $inherited, string $expected): void
	{
		$previous = getenv('PHP_CLI_SERVER_WORKERS');
		putenv($inherited === null ? 'PHP_CLI_SERVER_WORKERS' : "PHP_CLI_SERVER_WORKERS={$inherited}");

		try {
			[$exit, $io] = $this->command('server', $args);

			$this->assertSame(0, $exit, $io->errorOutput());
			$this->assertStringContainsString("workers={$expected}\n", $io->output());
		} finally {
			putenv($previous === false ? 'PHP_CLI_SERVER_WORKERS' : "PHP_CLI_SERVER_WORKERS={$previous}");
		}
	}

	public static function processCounts(): array
	{
		return [
			'default' => [[], null, 'unset'],
			'multiple' => [['--processes=4'], null, '4'],
			'one' => [['--processes=1'], null, 'unset'],
			'inherited' => [[], '3', '3'],
			'one overrides inherited' => [['--processes=1'], '3', 'unset'],
			'multiple override inherited' => [['--processes=2'], '3', '2'],
		];
	}

	#[DataProviderExternal(WorkerModeTest::class, 'invalidWorkerCounts')]
	public function testInvalidProcessCountFailsBeforeStartup(string $count): void
	{
		[$exit, $io] = $this->command('server', ["--processes={$count}"]);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('must be a positive integer', $io->errorOutput());
		$this->assertStringNotContainsString('workers=', $io->output());
	}

	public function testFrankenPhpHasNoProcessCount(): void
	{
		[$exit, $io] = $this->command('frankenphp', ['--processes=2']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('--processes', $io->errorOutput());
	}

	/**
	 * Runs a command against a backend that prints its process count and exits.
	 *
	 * @param list<string> $args
	 * @return array{int, BufferedIo}
	 */
	private function command(string $command, array $args): array
	{
		$executable = tempnam(sys_get_temp_dir(), 'fake-backend-');
		$this->assertIsString($executable);
		file_put_contents($executable, "#!/bin/sh\nprintf 'workers=%s\\n' \"\${PHP_CLI_SERVER_WORKERS-unset}\" >&2\n");
		chmod($executable, 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$argv = $_SERVER['argv'];
		$_SERVER['argv'] = ['run', $command, '--host=127.0.0.1', "--port={$port}", '--no-watch', ...$args];

		try {
			$io = new BufferedIo();
			$commands = new Commands([
				new Server('/tmp/public', executable: $executable),
				new FrankenPhp('/tmp/public', executable: $executable),
			]);

			return [new Runner($commands, $io)->run(), $io];
		} finally {
			$_SERVER['argv'] = $argv;
			unlink($executable);
		}
	}
}
