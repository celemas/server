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

	public function testProjectWithoutConfigSetsNothing(): void
	{
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertNull($config->server);
	}

	public function testConfigSetsTheServer(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "; Serve with FrankenPHP\nserver = frankenphp\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('frankenphp', $config->server);
	}

	public function testLocalConfigOverridesTheProjectConfig(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "server = builtin\n");
		file_put_contents("{$this->dir}/.cserve/config.local.ini", "server = frankenphp\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('frankenphp', $config->server);
	}

	public function testLocalConfigAloneSetsTheServer(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.local.ini", "server = frankenphp\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('frankenphp', $config->server);
	}

	public function testConfigSetsTheCommandLineOptions(): void
	{
		file_put_contents(
			"{$this->dir}/.cserve/config.ini",
			<<<'INI'
				host = 0.0.0.0
				port = 8080
				filter = '#^/assets/(css|js)/#'
				debug = true
				quiet = on
				open = false
				reload-port = "21300"
				processes = 4
				worker = true
				watch = no
				companions = off
				watch-files = 'src/**/*.php,!src/cache/**'
				INI,
		);
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('0.0.0.0', $config->host);
		$this->assertSame(8080, $config->port);
		$this->assertSame('#^/assets/(css|js)/#', $config->filter);
		$this->assertTrue($config->debug);
		$this->assertTrue($config->quiet);
		$this->assertFalse($config->open);
		$this->assertSame(21_300, $config->reloadPort);
		$this->assertSame(4, $config->processes);
		$this->assertSame(1, $config->worker);
		$this->assertFalse($config->watch);
		$this->assertFalse($config->companions);
		$this->assertSame(['src/**/*.php,!src/cache/**'], $config->watchFiles);
	}

	public function testWatchFilesTakeAList(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "watch-files[] = 'src/**'\nwatch-files[] = 'views/**'\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame(['src/**', 'views/**'], $config->watchFiles);
	}

	public function testLocalConfigOverridesOnlyItsOwnSettings(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "port = 8080\nopen = true\nworker = 4\n");
		file_put_contents("{$this->dir}/.cserve/config.local.ini", "open = false\nworker = false\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame(8080, $config->port);
		$this->assertFalse($config->open);
		$this->assertSame(0, $config->worker);
	}

	public function testConfigSetsTheRunScriptArguments(): void
	{
		file_put_contents(
			"{$this->dir}/.cserve/config.ini",
			"route-prefix = /site\nphp = /opt/php/bin/php\nversion = v1.12.7\n",
		);
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('/site', $config->routePrefix);
		$this->assertSame('/opt/php/bin/php', $config->php);
		$this->assertSame('1.12.7', $config->version);
	}

	public function testLocalExecutableReplacesTheProjectVersion(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "version = 1.12.7\n");
		file_put_contents("{$this->dir}/.cserve/config.local.ini", "frankenphp = /opt/frankenphp\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame('/opt/frankenphp', $config->frankenphp);
		$this->assertNull($config->version);
	}

	public function testCompanionsAreAddedReplacedAndRemovedByName(): void
	{
		file_put_contents(
			"{$this->dir}/.cserve/config.ini",
			"companion.css = 'npx @tailwindcss/cli --watch'\ncompanion.queue[] = php\ncompanion.queue[] = 'worker.php'\n",
		);
		file_put_contents("{$this->dir}/.cserve/config.local.ini", "companion.css = false\ncompanion.js = 'esbuild'\n");
		$config = ProjectConfig::load($this->dir);

		$this->assertInstanceOf(ProjectConfig::class, $config);
		$this->assertSame(
			['mail' => 'mailpit', 'queue' => ['php', 'worker.php'], 'js' => 'esbuild'],
			$config->commands(['css' => 'npx tailwindcss --watch', 'mail' => 'mailpit']),
		);
	}

	public function testInvalidLocalConfigIsAnError(): void
	{
		file_put_contents("{$this->dir}/.cserve/config.ini", "server = frankenphp\n");
		file_put_contents("{$this->dir}/.cserve/config.local.ini", "sever = builtin\n");

		$this->assertSame("Unknown setting 'sever' in .cserve/config.local.ini.", ProjectConfig::load($this->dir));
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
				"Invalid server 'caddy' in .cserve/config.ini: use builtin or frankenphp.",
			],
			'list' => ["server[] = frankenphp\n", 'Invalid server in .cserve/config.ini'],
			'boolean' => ["server = on\n", 'Invalid server in .cserve/config.ini'],
			'unknown setting' => ["sever = frankenphp\n", "Unknown setting 'sever' in .cserve/config.ini."],
			'option with dashes' => ["--port = 8080\n", "Unknown setting '--port' in .cserve/config.ini."],
			'port' => ["port = http\n", "Invalid port 'http' in .cserve/config.ini: use a port number."],
			'reload port' => [
				"reload-port = 2.5\n",
				"Invalid reload-port '2.5' in .cserve/config.ini: use a port number.",
			],
			'flag' => ["open = 1\n", "Invalid open '1' in .cserve/config.ini: use true or false."],
			'host' => ["host[] = localhost\n", 'Invalid host in .cserve/config.ini: use a host name or address.'],
			'worker' => [
				"worker = many\n",
				"Invalid worker 'many' in .cserve/config.ini: use true, false, or a count.",
			],
			'processes' => [
				"processes = all\n",
				"Invalid processes 'all' in .cserve/config.ini: use a positive integer.",
			],
			'watch files' => [
				"watch-files[src] = 'src/**'\n",
				'Invalid watch-files in .cserve/config.ini: use a string of glob patterns or a list of them.',
			],
			'executable' => ["php = ''\n", "Invalid php '' in .cserve/config.ini: use an executable."],
			'version' => [
				"version = latest\n",
				"Invalid version 'latest' in .cserve/config.ini: use a version like 1.12.7.",
			],
			'executable and version' => [
				"frankenphp = /opt/frankenphp\nversion = 1.12.7\n",
				'Set either frankenphp or version in .cserve/config.ini, not both.',
			],
			'companion' => [
				"companion.css = true\n",
				'Invalid companion.css in .cserve/config.ini: use a command line, a list of arguments, or false.',
			],
			'unnamed companion' => [
				"companion. = 'npx esbuild'\n",
				"Unknown setting 'companion.' in .cserve/config.ini.",
			],
			'syntax' => ["[server\n", 'Failed to read .cserve/config.ini: syntax error'],
		];
	}
}
