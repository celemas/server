<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Throwable;

/** @internal */
final class Console
{
	private static ?Throwable $exception = null;
	private static bool $trace = false;

	public static function enabled(): bool
	{
		$value = $_SERVER['CELEMA_CLI_SERVER'] ?? getenv('CELEMA_CLI_SERVER');

		return $value === '1' || $value === 'frankenphp';
	}

	public static function recordException(Throwable $exception, bool $trace): void
	{
		if (!self::enabled()) {
			return;
		}

		if (self::frankenPhp()) {
			self::writeFrankenException($exception, $trace);

			return;
		}

		self::$exception = $exception;
		self::$trace = $trace;
	}

	public static function hasException(): bool
	{
		return self::$exception !== null;
	}

	public static function clearException(): void
	{
		self::$exception = null;
		self::$trace = false;
	}

	public static function flushException(): void
	{
		$exception = self::$exception;
		$trace = self::$trace;
		self::clearException();

		if ($exception !== null) {
			self::writeException($exception, $trace);
		}
	}

	private static function frankenPhp(): bool
	{
		$value = $_SERVER['CELEMA_CLI_SERVER'] ?? getenv('CELEMA_CLI_SERVER');

		return $value === 'frankenphp';
	}

	private static function writeFrankenException(Throwable $exception, bool $withTrace): void
	{
		// Keep the details with the marker so the parent can print them after the access log.
		$context = json_encode([
			'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '-')),
			'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
			'lines' => self::exceptionLines($exception, $withTrace),
		], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

		if (is_string($context)) {
			self::write('celema-exception ' . $context);
		}
	}

	private static function writeException(Throwable $exception, bool $withTrace): void
	{
		foreach (self::exceptionLines($exception, $withTrace) as $line) {
			self::write($line);
		}
	}

	/** @return list<string> */
	private static function exceptionLines(Throwable $exception, bool $withTrace): array
	{
		$lines = [
			$exception::class . ': ' . self::message($exception),
			'in ' . $exception->getFile() . ':' . $exception->getLine(),
		];

		if (!$withTrace) {
			return $lines;
		}

		$trace = trim($exception->getTraceAsString());

		if ($trace === '') {
			return $lines;
		}

		$lines[] = 'Trace:';

		foreach (explode("\n", $trace) as $line) {
			$lines[] = $line;
		}

		return $lines;
	}

	private static function message(Throwable $exception): string
	{
		$message = trim(str_replace(["\r", "\n"], ' ', $exception->getMessage()));

		return $message === '' ? '(no message)' : $message;
	}

	private static function write(string $line): void
	{
		error_log($line);
	}
}
