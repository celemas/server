<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\ProjectConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
	private string $dir = '';

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir() . '/celema-config-' . bin2hex(random_bytes(4));
		mkdir("{$this->dir}/.cserve", recursive: true);
	}

	protected function tearDown(): void
	{
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testProjectWithoutConfigUsesTheBuiltinServer(): void
	{
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('server', $config->server);
	}

	public function testConfigSetsTheServer(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "; Serve with FrankenPHP\nserver = frankenphp\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('frankenphp', $config->server);
	}

	#[DataProvider('invalidConfigs')]
	public function testInvalidConfigIsAnError(string $contents, string $expected): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", $contents);

		$this->assertStringStartsWith($expected, (string) ProjectConfig::load($this->dir));
	}

	public static function invalidConfigs(): array
	{
		return [
			'unknown server' => [
				"server = caddy\n",
				"Invalid server 'caddy' in .cserve/config.ini: use server or frankenphp.",
			],
			'list' => ["server[] = frankenphp\n", 'Invalid server in .cserve/config.ini'],
			'boolean' => ["server = on\n", 'Invalid server in .cserve/config.ini'],
			'unknown setting' => ["sever = frankenphp\n", "Unknown setting 'sever' in .cserve/config.ini."],
			'syntax' => ["[server\n", 'Failed to read .cserve/config.ini: syntax error'],
		];
	}
}
