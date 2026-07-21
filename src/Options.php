<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Args;
use InvalidArgumentException;

/** @internal */
final class Options
{
	public string $host = 'localhost';
	public int $port = 1983;
	public string $filter = '';
	public bool $debug = false;
	public bool $quiet = false;
	public bool $watch = false;
	/** @var list<string> */
	public array $watchFiles = Setup::DEFAULT_WATCH;

	public static function from(int $defaultPort, array|string $defaultWatch, Args $args): self
	{
		$options = new self();
		$options->host = $args->opt('-h', $args->opt('--host', 'localhost'));
		$options->port = self::port($args->opt('-p', $args->opt('--port', (string) $defaultPort)));
		$options->filter = self::filter($args->opt('-f', $args->opt('--filter', '')));
		$options->debug = $args->has('-d') || $args->has('--debug');
		$options->quiet = $args->has('-q') || $args->has('--quiet');
		$options->watch = $args->has('-w') || $args->has('--watch');
		$options->watchFiles = self::watchFiles($args, $defaultWatch);

		return $options;
	}

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

	public static function filter(string $pattern): string
	{
		if ($pattern === '') {
			return '';
		}

		$result = ErrorTrap::run(static fn(): mixed => preg_match($pattern, ''));

		if ($result === false) {
			throw new InvalidArgumentException("Invalid filter regex '{$pattern}'.");
		}

		return $pattern;
	}

	/** @return list<string> */
	public static function watchFiles(Args $args, array|string $defaultWatch): array
	{
		$watch = WatchPattern::list($defaultWatch);
		$values = self::watchValues($args);

		if ($values === []) {
			return $watch;
		}

		return WatchPattern::list($values);
	}

	/** @return list<string> */
	private static function watchValues(Args $args): array
	{
		$values = [];

		if ($args->has('-w')) {
			$values = array_merge($values, $args->opts('-w', []));
		}

		if ($args->has('--watch')) {
			$values = array_merge($values, $args->opts('--watch', []));
		}

		return $values;
	}
}
