<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Args;
use Celema\Console\BufferedIo;
use Celema\Server\Companion;
use Celema\Server\Ports;
use Celema\Server\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the server command against a stand-in backend that serves for a
 * second, with companion processes alongside it.
 */
final class CompanionTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/celema-companion-' . bin2hex(random_bytes(4));
		mkdir($this->dir);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testCompanionOutputShowsItsName(): void
	{
		[$exit, $io] = $this->serve(['css' => "printf '\\033[32mDone\\033[0m in 12ms\\n\\n'; sleep 30"]);

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringContainsString("css Done in 12ms\n", $io->output());
		$this->assertStringNotContainsString('exited', $io->output());
	}

	public function testRedrawnLinesShowTheirLastState(): void
	{
		$companion = Companion::start('build', ['true'], $io = new BufferedIo());
		$this->assertInstanceOf(Companion::class, $companion);
		$companion->line("10%\r50%\r100%\r\n");
		$companion->line("\n");
		$companion->stop();

		$this->assertSame("build 100%\n", $io->output());
	}

	public function testServerKeepsRunningWhenACompanionExits(): void
	{
		[$exit, $io] = $this->serve(['css' => 'echo bye; exit 3']);

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertMatchesRegularExpression(
			'#css bye\n.*css exited with code 3\n.*200 GET /after#s',
			$io->output(),
		);
	}

	public function testCompanionsStopWithTheServerTogetherWithTheirChildren(): void
	{
		[$exit, $io] = $this->serve([
			'watch' => 'sleep 30 & echo $! > child.pid; echo $$ > companion.pid; wait',
		]);

		$this->assertSame(0, $exit, $io->errorOutput());

		foreach (['companion.pid', 'child.pid'] as $file) {
			$this->assertFileExists("{$this->dir}/{$file}");
			$this->assertStopped((int) file_get_contents("{$this->dir}/{$file}"));
		}
	}

	public function testCompanionInputStaysOpen(): void
	{
		// cat exits once its input closes.
		[$exit, $io] = $this->serve(['input' => ['cat']]);

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertStringNotContainsString('exited', $io->output());
	}

	public function testCompanionsCanBeSkipped(): void
	{
		[$exit, $io] = $this->serve(['marker' => 'touch marker'], ['--no-companions']);

		$this->assertSame(0, $exit, $io->errorOutput());
		$this->assertFileDoesNotExist("{$this->dir}/marker");
	}

	#[DataProvider('invalidCompanions')]
	public function testInvalidCompanionsAreRejected(array $companions, string $message): void
	{
		[$exit, $io] = $this->serve($companions);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString($message, $io->errorOutput());
	}

	public static function invalidCompanions(): array
	{
		return [
			'unnamed' => [['npx @tailwindcss/cli --watch'], 'Companion processes need names'],
			'empty' => [['css' => ' '], "The companion process 'css' needs a command"],
			'no arguments' => [['css' => []], "The companion process 'css' needs a command"],
			'mixed arguments' => [['css' => ['npx', 1]], "The companion process 'css' needs a command"],
		];
	}

	/**
	 * @param array<array-key, mixed> $companions
	 * @param list<string> $args
	 * @return array{int, BufferedIo}
	 */
	private function serve(array $companions, array $args = []): array
	{
		$backend = "{$this->dir}/php";
		file_put_contents($backend, <<<'SH'
			#!/bin/sh
			[ "$1" = -S ] || exit 0
			sleep 1
			printf 'celema-request 200 GET 0.1 -- /after\n' >&2
			SH);
		chmod($backend, 0o755);
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$io = new BufferedIo();
			$exit = (new Server($this->dir, executable: $backend, companions: $companions))(
				new Args(['--host=127.0.0.1', "--port={$port}", '--no-watch', ...$args]),
				$io,
			);

			return [$exit, $io];
		} finally {
			chdir($cwd);
		}
	}

	private function assertStopped(int $pid): void
	{
		// A killed process may take a moment to be reaped.
		for ($i = 0; $i < 50 && posix_kill($pid, 0); $i++) {
			usleep(20_000);
		}

		$this->assertFalse(posix_kill($pid, 0), "Process {$pid} still runs.");
	}
}
