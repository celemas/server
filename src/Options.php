<?php

declare(strict_types=1);

namespace Celema\Server;

use InvalidArgumentException;

/**
 * The settings of a run, from the command line and the run script.
 *
 * @internal
 */
final readonly class Options
{
	/** @var list<string> */
	public array $watchFiles;

	/**
	 * Throws an InvalidArgumentException for an invalid port, filter, or
	 * count. Without explicit patterns, `$watchFiles` takes the defaults.
	 *
	 * @param list<string> $watchFiles
	 * @param array|string $defaultWatch
	 */
	// One parameter per setting; grouping them would only add indirection.
	// @mago-expect lint:excessive-parameter-list
	public function __construct(
		public string $host = 'localhost',
		public int $port = 2130,
		public string $filter = '',
		public bool $debug = false,
		public bool $quiet = false,
		public bool $watch = true,
		public ?int $workers = null,
		public ?int $processes = null,
		public bool $open = false,
		public ?int $reloadPort = null,
		public bool $companions = true,
		array $watchFiles = [],
		array|string $defaultWatch = Setup::DEFAULT_WATCH,
	) {
		self::port($port);
		self::filter($filter);

		if ($reloadPort !== null) {
			self::port($reloadPort);
		}

		self::count($workers, 'Worker');
		self::count($processes, 'Process');
		$this->watchFiles = WatchPattern::list($watchFiles === [] ? $defaultWatch : $watchFiles);
	}

	private static function count(?int $count, string $what): void
	{
		if ($count !== null && $count < 1) {
			throw new InvalidArgumentException("{$what} count '{$count}' must be a positive integer.");
		}
	}

	private static function port(int $port): void
	{
		if ($port < 1 || $port > 65_535) {
			throw new InvalidArgumentException("Port '{$port}' must be between 1 and 65535.");
		}
	}

	private static function filter(string $pattern): void
	{
		if ($pattern === '') {
			return;
		}

		/** @var int|false $result */
		$result = ErrorTrap::run(static fn(): mixed => preg_match($pattern, ''));

		if ($result === false) {
			throw new InvalidArgumentException("Invalid filter regex '{$pattern}'.");
		}
	}
}
