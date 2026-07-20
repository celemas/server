<?php

declare(strict_types=1);

if (!function_exists('serverEcho')) {
	/**
	 * Reports a handled request to the parent server command as a plain
	 * structured line; PhpOutput renders it as a request log line.
	 */
	function serverEcho(int $statusCode, string $msg, float $time, bool $fromHandler = false): void
	{
		$xRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? null;
		$xhr = is_string($xRequestedWith) && strtolower($xRequestedWith) === 'xmlhttprequest';
		$method = isset($_SERVER['REQUEST_METHOD'])
			? strtoupper($_SERVER['REQUEST_METHOD'])
			: '';

		error_log(sprintf(
			'celema-request %d %s %.5f %s%s %s',
			$statusCode,
			$method === '' ? '-' : $method,
			round($time, 5),
			$fromHandler ? 'e' : '-',
			$xhr ? 'x' : '-',
			$msg,
		));
	}
}
