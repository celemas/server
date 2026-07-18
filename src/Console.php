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

		return $value === '1';
	}

	public static function recordException(Throwable $exception, bool $trace): void
	{
		if (!self::enabled()) {
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

		if ($exception === null) {
			return;
		}
		self::write($exception::class . ': ' . self::message($exception));
		self::write('in ' . $exception->getFile() . ':' . $exception->getLine());

		if (!$trace) {
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
