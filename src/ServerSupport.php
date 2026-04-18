<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use InvalidArgumentException;
use Throwable;

final readonly class ServerSupport
{
	private const BROWSER_SYNC_FILES = '**/*.php, **/*.css, **/*.js';

	public function __construct(
		private string $docroot,
		private string $routePrefix,
	) {}

	public static function port(string $value): int
	{
		if (!preg_match('/^\d+$/', $value)) {
			throw new InvalidArgumentException("Invalid port '{$value}'.");
		}

		$port = (int) $value;

		if ($port < 1 || $port > 65_535) {
			throw new InvalidArgumentException("Port '{$value}' must be between 1 and 65535.");
		}

		return $port;
	}

	public static function backendPort(int $port): int
	{
		if ($port >= 65_535) {
			throw new InvalidArgumentException(
				'BrowserSync needs a free backend port after the public port.',
			);
		}

		return $port + 1;
	}

	public function missingBrowserSyncDependencies(): array
	{
		$missing = [];

		foreach (['node', 'npx'] as $command) {
			if ($this->commandAvailable($command)) {
				continue;
			}

			$missing[] = $command;
		}

		return $missing;
	}

	public function portUnavailableMessage(string $host, int $port): ?string
	{
		$errorCode = 0;
		$errorMessage = '';
		$server = ServerErrorTrap::run(
			static fn(): mixed => stream_socket_server("tcp://{$host}:{$port}", $errorCode, $errorMessage),
			$errorMessage,
		);

		if ($server === false) {
			$message = "Port {$host}:{$port} is not available";

			if ($errorMessage !== '') {
				$message .= ": {$errorMessage}";
			}

			return $message . '.';
		}

		if (is_resource($server)) {
			fclose($server);
		}

		return null;
	}

	public function phpEnvironment(bool $debugger): array
	{
		$environment = array_merge((array) getenv(), [
			'DUON_CLI_SERVER' => '1',
			'DUON_DOCUMENT_ROOT' => $this->docroot,
			'DUON_TERMINAL_COLUMNS' => $this->terminalColumns(),
			'DUON_ROUTE_PREFIX' => $this->routePrefix,
		]);

		if ($debugger) {
			$environment['XDEBUG_SESSION'] = '1';
		}

		return $environment;
	}

	public function phpCommand(string $host, int $port, bool $quiet): array
	{
		$command = ['php', '-S', "{$host}:{$port}"];

		if ($quiet) {
			$command[] = '-q';
		}

		$command[] = '-t';
		$command[] = $this->docroot;
		$command[] = __DIR__ . DIRECTORY_SEPARATOR . 'CliRouter.php';

		return $command;
	}

	public function browserSyncCommand(string $host, int $port, int $backendPort, bool $quiet): array
	{
		$command = [
			'npx',
			'browser-sync',
			'start',
			'--proxy',
			"http://{$host}:{$backendPort}",
			'--files',
			self::BROWSER_SYNC_FILES,
			'--port',
			(string) $port,
			'--host',
			$host,
			'--no-ui',
			'--no-notify',
		];

		if ($quiet) {
			$command[] = '--logLevel';
			$command[] = 'silent';
		}

		return $command;
	}

	private function terminalColumns(): string
	{
		try {
			$size = trim(exec('stty size') ?: '');

			if ($size === '') {
				return '80';
			}

			$parts = explode(' ', $size);

			return $parts[1] ?? '80';
		} catch (Throwable) {
			return '80';
		}
	}

	private function commandAvailable(string $command): bool
	{
		$output = [];
		$exitCode = 1;
		exec('which ' . escapeshellarg($command) . ' 2>/dev/null', $output, $exitCode);

		return $exitCode === 0;
	}
}
