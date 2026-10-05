<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\Capture;
use Celema\Server\ProcessGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProcessGroupTest extends TestCase
{
	public function testProcessesRunInGroupsWhereThisPhpCanSignalThem(): void
	{
		if (DIRECTORY_SEPARATOR !== '/' || !function_exists('pcntl_signal') || !function_exists('posix_kill')) {
			$this->markTestSkipped('Needs the pcntl and posix extensions on a Unix-like system.');
		}

		// Also where the wrapper's PHP lacks extensions without ini files,
		// like posix on Debian.
		$this->assertTrue(ProcessGroup::available());
	}

	/** @param list<string> $disabled */
	#[DataProvider('missingExtensions')]
	public function testCheckNamesTheMissingExtensions(array $disabled, string $expected): void
	{
		$check = Capture::output([
			PHP_BINARY,
			'-n',
			'-d',
			'disable_functions=' . implode(',', $disabled),
			dirname(__DIR__) . '/src/group.php',
			'--check',
		]);

		$this->assertSame($expected, $check);
	}

	public static function missingExtensions(): array
	{
		return [
			'none' => [[], 'ok'],
			'posix' => [['posix_setpgid'], 'posix'],
			'both' => [['pcntl_exec', 'posix_setpgid'], 'pcntl posix'],
		];
	}
}
