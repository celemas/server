<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\ErrorTrap;
use PHPUnit\Framework\TestCase;

final class CliRouterTest extends TestCase
{
	private string $docroot;

	protected function setUp(): void
	{
		$docroot = tempnam(sys_get_temp_dir(), 'celema-router-');
		$this->assertIsString($docroot);
		unlink($docroot);
		mkdir($docroot);
		$this->docroot = $docroot;
	}

	protected function tearDown(): void
	{
		foreach ((array) glob("{$this->docroot}/*") as $file) {
			unlink((string) $file);
		}

		rmdir($this->docroot);
	}

	public function testServesPlainScripts(): void
	{
		file_put_contents("{$this->docroot}/index.php", "<?php\nhttp_response_code(404);\necho 'plain';\n");

		[$body, $log] = $this->request('/missing');

		$this->assertSame('plain', $body);
		$this->assertMatchesRegularExpression('#celema-request 404 GET [\d.]+ -- /missing#', $log);
	}

	public function testLogsTheStatusOfReturnedResponses(): void
	{
		file_put_contents(
			"{$this->docroot}/index.php",
			"<?php\necho 'psr';\nreturn new class { public function getStatusCode(): int { return 201; } };\n",
		);

		[$body, $log] = $this->request('/created');

		$this->assertSame('psr', $body);
		$this->assertMatchesRegularExpression('#celema-request 201 GET [\d.]+ -- /created#', $log);
	}

	public function testPassesEveryPathToTheFrontController(): void
	{
		file_put_contents("{$this->docroot}/index.php", "<?php\necho 'app';\n");

		[$body] = $this->request('/phpinfo');

		$this->assertSame('app', $body);
	}

	/** @return array{string, string} */
	private function request(string $path): array
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$this->assertIsResource($socket);
		$address = (string) stream_socket_get_name($socket, false);
		fclose($socket);
		$log = tempnam(sys_get_temp_dir(), 'celema-router-log-');
		$this->assertIsString($log);
		$process = proc_open(
			[PHP_BINARY, '-S', $address, '-t', $this->docroot, dirname(__DIR__) . '/src/CliRouter.php'],
			[0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']],
			$pipes,
			env_vars: [...getenv(), 'CELEMA_CLI_SERVER' => '1', 'CELEMA_DOCUMENT_ROOT' => $this->docroot],
		);
		$this->assertIsResource($process);

		try {
			$body = false;

			for ($i = 0; $i < 50 && $body === false; $i++) {
				usleep(100_000);
				$context = stream_context_create(['http' => ['ignore_errors' => true]]);
				/** @var string|false $body */
				$body = ErrorTrap::run(static fn(): mixed => file_get_contents(
					"http://{$address}{$path}",
					context: $context,
				));
			}

			$this->assertIsString($body, 'The PHP server did not respond.');

			return [$body, (string) file_get_contents($log)];
		} finally {
			proc_terminate($process);
			proc_close($process);
			unlink($log);
		}
	}
}
