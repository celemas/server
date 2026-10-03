<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Args;
use InvalidArgumentException;

/**
 * The parsed command-line options, one property per option.
 *
 * @internal
 */
// One property per option; grouping them would only add indirection.
// @mago-expect lint:too-many-properties
final class Options
{
	public string $host = 'localhost';
	public int $port = 2130;
	public string $filter = '';
	public bool $debug = false;
	public bool $quiet = false;
	public bool $watch = true;
	public ?int $workers = null;
	public ?int $processes = null;
	public bool $open = false;
	public ?int $reloadPort = null;
	public bool $companions = true;
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
		$options->workers = $args->has('--worker') ? self::count($args->opt('--worker', '1'), 'Worker') : null;
		$options->processes = $args->has('--processes') ? self::count($args->opt('--processes'), 'Process') : null;
		$options->watch = !$args->has('--no-watch');
		$options->open = $args->has('-o') || $args->has('--open');
		$options->companions = !$args->has('--no-companions');
		$reloadPort = $args->opt('--reload-port', '');
		$options->reloadPort = $reloadPort === '' ? null : self::port($reloadPort);
		$options->watchFiles = self::watchFiles($args, $defaultWatch);

		return $options;
	}

	private static function count(string $value, string $what): int
	{
		$count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

		if (!preg_match('/^[1-9][0-9]*$/D', $value) || $count === false) {
			throw new InvalidArgumentException("{$what} count '{$value}' must be a positive integer.");
		}

		return $count;
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

		/** @var int|false $result */
		$result = ErrorTrap::run(static fn(): mixed => preg_match($pattern, ''));

		if ($result === false) {
			throw new InvalidArgumentException("Invalid filter regex '{$pattern}'.");
		}

		return $pattern;
	}

	/** @return list<string> */
	public static function watchFiles(Args $args, array|string $defaultWatch): array
	{
		$values = $args->opts('--watch-files');

		return WatchPattern::list($values === [] ? $defaultWatch : $values);
	}
}
