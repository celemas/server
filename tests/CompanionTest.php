<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Server\Companion;
use Celema\Server\ErrorTrap;
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
		[$exit, $buffer] = $this->serve(['css' => "printf '\\033[32mDone\\033[0m in 12ms\\n\\n'; sleep 30"]);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringContainsString("css Done in 12ms\n", $buffer->output());
		$this->assertStringNotContainsString('exited', $buffer->output());
	}

	public function testRedrawnLinesShowTheirLastState(): void
	{
		$companion = Companion::start('build', ['true'], new Io($buffer = new Buffer()));
		$this->assertInstanceOf(Companion::class, $companion);
		$companion->line("10%\r50%\r100%\r\n");
		$companion->line("\n");
		$companion->stop();

		$this->assertSame("build 100%\n", $buffer->output());
	}

	public function testServerKeepsRunningWhenACompanionExits(): void
	{
		[$exit, $buffer] = $this->serve(['css' => 'echo bye; exit 3']);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertMatchesRegularExpression(
			'#css bye\n.*css exited with code 3\n.*200 GET /after#s',
			$buffer->output(),
		);
	}

	public function testCompanionsStopWithTheServerTogetherWithTheirChildren(): void
	{
		[$exit, $buffer] = $this->serve([
			'watch' => 'sleep 30 & echo $! > child.pid; echo $$ > companion.pid; wait',
		]);

		$this->assertSame(0, $exit, $buffer->errorOutput());

		foreach (['companion.pid', 'child.pid'] as $file) {
			$this->assertFileExists("{$this->dir}/{$file}");
			$this->assertStopped((int) file_get_contents("{$this->dir}/{$file}"));
		}
	}

	public function testCompanionInputStaysOpen(): void
	{
		// cat exits once its input closes.
		[$exit, $buffer] = $this->serve(['input' => ['cat']]);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertStringNotContainsString('exited', $buffer->output());
	}

	public function testCompanionsCanBeSkipped(): void
	{
		[$exit, $buffer] = $this->serve(['marker' => 'touch marker'], ['--no-companions']);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertFileDoesNotExist("{$this->dir}/marker");
	}

	#[DataProvider('invalidCompanions')]
	public function testInvalidCompanionsAreRejected(array $companions, string $message): void
	{
		[$exit, $buffer] = $this->serve($companions);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString($message, $buffer->errorOutput());
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
	 * @return array{int, Buffer}
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
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run(new Server($this->dir, php: $backend, companions: $companions), $io, [
				'--host=127.0.0.1',
				"--port={$port}",
				'--no-watch',
				...$args,
			]);

			return [$exit, $buffer];
		} finally {
			chdir($cwd);
		}
	}

	private function assertStopped(int $pid): void
	{
		// A killed process may take a moment to be reaped.
		for ($i = 0; $i < 50 && self::running($pid); $i++) {
			usleep(20_000);
		}

		$this->assertFalse(self::running($pid), "Process {$pid} still runs.");
	}

	/**
	 * Whether the process runs. A zombie has stopped, but stays until its
	 * parent reaps it: an orphan's new parent may never do so, like the
	 * `tail` that runs as the init process of a CI container.
	 */
	private static function running(int $pid): bool
	{
		if (!posix_kill($pid, 0)) {
			return false;
		}

		// The state follows the command name, which may contain spaces.
		$stat = ErrorTrap::run(static fn(): string|false => file_get_contents("/proc/{$pid}/stat"));

		return !is_string($stat) || preg_match('/\) Z /', $stat) !== 1;
	}
}
