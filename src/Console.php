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
			self::writeMarker();
			self::writeException($exception, $trace);

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

	private static function writeMarker(): void
	{
		$context = json_encode([
			'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '-')),
			'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
		], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);

		if (is_string($context)) {
			self::write('celema-exception ' . $context);
		}
	}

	private static function writeException(Throwable $exception, bool $withTrace): void
	{
		self::write($exception::class . ': ' . self::message($exception));
		self::write('in ' . $exception->getFile() . ':' . $exception->getLine());

		if (!$withTrace) {
			return;
		}

		$trace = trim($exception->getTraceAsString());

		if ($trace === '') {
			return;
		}

		self::write('Trace:');

		foreach (explode("\n", $trace) as $line) {
			self::write($line);
		}
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
