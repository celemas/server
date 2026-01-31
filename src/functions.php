<?php

declare(strict_types=1);

if (!function_exists('getServerEchoSpacer')) {
	function getServerEchoSpacer(string $leftSide, string $rightSide, int $columns): string
	{
		$strlen = function (string $str): int {
			if (function_exists('mb_strlen')) {
				return mb_strlen($str);
			}

			return strlen($str);
		};
		$leftLen = $strlen(preg_replace('#\\x1b[[][^A-Za-z]*[A-Za-z]#', '', $leftSide));
		$rightLen = $strlen(preg_replace('#\\x1b[[][^A-Za-z]*[A-Za-z]#', '', $rightSide));

		if ($leftLen > $columns) {
			$leftLen = $leftLen % $columns;
		}

		$spacer = str_repeat('.', $columns - (($leftLen + $rightLen + 2) % $columns));

		return " \033[1;30m{$spacer}\033[0m ";
	}
}

if (!function_exists('serverEcho')) {
	function serverEcho(int $statusCode, string $msg, float $time, bool $fromHandler = false): void
	{
		$isXhr = (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
			&& strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ? '[XHR]' : '';
		$method = isset($_SERVER['REQUEST_METHOD'])
			? strtoupper($_SERVER['REQUEST_METHOD']) : '';
		$statusColor = match (true) {
			$statusCode >= 200 && $statusCode < 300 => '32',
			$statusCode >= 300 && $statusCode < 400 => '34',
			$statusCode >= 400 && $statusCode < 500 => '33',
			$statusCode >= 500 => '31',
			default => '37',
		};
		$duration = sprintf('%.5f', round($time, 5));
		$columns = (int) getenv('DUON_TERMINAL_COLUMNS');

		list($usec, $sec) = explode(' ', microtime());
		$usec = str_replace('0.', '.', $usec);
		$timestamp = date('H:i:s', (int) $sec) . substr($usec, 0, 3);
		$url = urldecode($msg);

		$leftSide
			// timestamp
			= "\033[0;37m{$timestamp}\033[0m "
			// status code
			. "\033[0;{$statusColor}m{$statusCode}\033[0m "
			// request method
			. "{$method} "
			// request uri
			. "\033[0;{$statusColor}m{$url}\033[0m";
		$rightSide
			// from error handler
			= ($fromHandler ? "\033[0;36m[EXC]\033[0m" : '')
			// xhr indicator
			. "\033[0;36m{$isXhr}\033[0m"
			. ($fromHandler || $isXhr ? ' ' : '')
			// time
			. "\033[0;37m{$duration}s\033[0m";

		error_log($leftSide . getServerEchoSpacer($leftSide, $rightSide, $columns) . $rightSide);
	}
}
