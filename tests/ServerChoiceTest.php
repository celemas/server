<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Server\Ports;
use Celema\Server\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which server the `server` command runs, from its argument, the project's settings, or its own. */
final class ServerChoiceTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		$dir = sys_get_temp_dir() . '/celema-choice-' . bin2hex(random_bytes(4));
		mkdir("{$dir}/.cserve", recursive: true);
		$this->dir = (string) realpath($dir);

		// Both backends answer the version queries and record that they served.
		foreach (['php', 'frankenphp'] as $name) {
			file_put_contents(
				"{$this->dir}/{$name}",
				"#!/bin/sh\ncase \"\$1\" in -n|version|php-cli) exit 0;; esac\ntouch {$this->dir}/served-{$name}\n",
			);
			chmod("{$this->dir}/{$name}", 0o755);
		}
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	/** @param list<string> $args */
	#[DataProvider('choices')]
	public function testServesWithTheChosenServer(
		string $default,
		?string $config,
		?string $local,
		array $args,
		string $expected,
	): void {
		if ($config !== null) {
			file_put_contents("{$this->dir}/.cserve/config.ini", "server = {$config}\n");
		}

		if ($local !== null) {
			file_put_contents("{$this->dir}/.cserve/config.local.ini", "server = {$local}\n");
		}

		[$exit, $buffer] = $this->serve($args, $default);

		$this->assertSame(0, $exit, $buffer->errorOutput());
		$this->assertFileExists("{$this->dir}/served-{$expected}");
		$this->assertFileDoesNotExist("{$this->dir}/served-" . ($expected === 'php' ? 'frankenphp' : 'php'));
	}

	public static function choices(): array
	{
		return [
			'default' => ['builtin', null, null, [], 'php'],
			'run script default' => ['frankenphp', null, null, [], 'frankenphp'],
			'project setting' => ['builtin', 'frankenphp', null, [], 'frankenphp'],
			'local setting' => ['builtin', 'frankenphp', 'builtin', [], 'php'],
			'argument' => ['builtin', 'builtin', 'builtin', ['frankenphp'], 'frankenphp'],
			'builtin argument' => ['frankenphp', 'frankenphp', null, ['builtin'], 'php'],
		];
	}

	public function testUnknownServerIsAnError(): void
	{
		[$exit, $buffer] = $this->serve(['caddy']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Unknown server 'caddy': use builtin or frankenphp.", $buffer->errorOutput());
	}

	public function testInvalidProjectSettingIsAnError(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "server = caddy\n");

		[$exit, $buffer] = $this->serve([]);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString("Invalid server 'caddy' in .cserve/config.ini", $buffer->errorOutput());
	}

	/** @param list<string> $args */
	#[DataProvider('foreignOptions')]
	public function testOptionsOfTheOtherServerAreErrors(array $args, string $expected): void
	{
		[$exit, $buffer] = $this->serve($args);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString($expected, $buffer->errorOutput());
		$this->assertFileDoesNotExist("{$this->dir}/served-php");
		$this->assertFileDoesNotExist("{$this->dir}/served-frankenphp");
	}

	public static function foreignOptions(): array
	{
		return [
			'worker' => [
				['builtin', '--worker'],
				"--worker needs the frankenphp server: run 'server frankenphp --worker'.",
			],
			'processes' => [
				['frankenphp', '--processes=2'],
				"--processes needs the builtin server: run 'server builtin --processes'.",
			],
		];
	}

	/**
	 * Runs the command in the project directory.
	 *
	 * @param list<string> $args
	 * @return array{int, Buffer}
	 */
	private function serve(array $args, string $default = 'builtin'): array
	{
		$port = Ports::ephemeral();
		$this->assertIsInt($port);
		$command = new Server(
			$this->dir,
			server: $default,
			php: "{$this->dir}/php",
			frankenphp: "{$this->dir}/frankenphp",
		);
		$cwd = (string) getcwd();
		chdir($this->dir);

		try {
			$buffer = new Buffer();
			$io = new Io($buffer);
			$exit = Cli::run($command, $io, [...$args, '--host=127.0.0.1', "--port={$port}", '--no-watch']);
		} finally {
			chdir($cwd);
		}

		return [$exit, $buffer];
	}
}
