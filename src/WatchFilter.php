<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Decides which directories a scan enters and which files it watches.
 *
 * @internal
 */
final readonly class WatchFilter
{
	/**
	 * Skipped while scanning, unless a pattern's base already points
	 * into them. Scanning these would make polling expensive.
	 */
	private const array SKIPPED = ['node_modules', 'vendor'];

	/**
	 * @param array<string, list<WatchGlob>> $globs Grouped by base directory
	 * @param list<WatchGlob> $ignore
	 */
	public function __construct(
		private array $globs,
		private array $ignore = [],
	) {}

	/** @return list<string> */
	public function bases(): array
	{
		return array_keys($this->globs);
	}

	public function descends(string $base, string $entry, string $path): bool
	{
		if ($entry[0] === '.' || in_array($entry, self::SKIPPED, true)) {
			return false;
		}

		// An ignored directory is skipped as a whole.
		if (array_any($this->ignore, static fn(WatchGlob $glob): bool => $glob->coversDirectory($path))) {
			return false;
		}

		return array_any($this->globs[$base] ?? [], static fn(WatchGlob $glob): bool => $glob->deep);
	}

	public function includes(string $base, string $path): bool
	{
		return (
			array_any($this->globs[$base] ?? [], static fn(WatchGlob $glob): bool => $glob->matches($path))
				&& !array_any($this->ignore, static fn(WatchGlob $glob): bool => $glob->matches($path))
		);
	}
}
