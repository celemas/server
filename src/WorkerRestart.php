<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Restarts the FrankenPHP workers through the admin API, which the
 * generated configuration binds to a loopback port only.
 *
 * FrankenPHP's own `watch` directive is not used: it does not follow the
 * symlinked package directories of path repositories.
 *
 * @internal
 */
final readonly class WorkerRestart
{
	public function __construct(
		public int $adminPort,
		private int $timeout = 10,
	) {}

	/**
	 * A worker keeps loaded code in memory; only stylesheets and scripts
	 * the browser loads itself are picked up without a restart.
	 *
	 * @param list<string> $files
	 */
	public static function needed(array $files): bool
	{
		foreach ($files as $file) {
			$extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

			if ($extension !== 'css' && $extension !== 'js') {
				return true;
			}
		}

		return false;
	}

	/** Returns an error message, or null once the workers restarted. */
	public function __invoke(): ?string
	{
		$url = "http://127.0.0.1:{$this->adminPort}/frankenphp/workers/restart";
		$context = stream_context_create([
			'http' => [
				'method' => 'POST',
				'header' => "Content-Length: 0\r\n",
				'timeout' => $this->timeout,
				'ignore_errors' => true,
			],
		]);
		$response = null;
		/** @var string|false $body */
		$body = ErrorTrap::run(
			static function () use ($url, $context, &$response): mixed {
				$result = file_get_contents($url, false, $context);
				$response = http_get_last_response_headers();

				return $result;
			},
			$error,
		);

		if ($body === false || !is_array($response)) {
			return 'Could not restart the worker: ' . ($error ?? 'no response from the FrankenPHP admin API');
		}

		$status = (string) ($response[0] ?? '');

		if (preg_match('/^HTTP\/\S+ 2\d\d\b/', $status) !== 1) {
			return 'Could not restart the worker: ' . trim($status . ' ' . $body);
		}

		return null;
	}
}
