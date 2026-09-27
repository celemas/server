<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Collects the stat data of all files matching the watch globs.
 *
 * Directory listings are cached until the directory's modification
 * time changes, which happens when entries are added, removed, or
 * renamed. A scan then only stats the directories and matching files.
 *
 * @internal
 *
 * @psalm-import-type Stamp from FileStamp
 * @psalm-type Listing = array{mtime: int, dirs: list<string>, files: list<string>}
 */
final class FileScan
{
	/** @var array<string, array<string, Listing>> Per base, then per directory */
	private array $listings = [];

	public function __construct(
		private readonly WatchFilter $filter,
	) {}

	/** @return array<string, Stamp> */
	public function run(): array
	{
		clearstatcache();
		$stamps = [];
		$now = time();

		foreach ($this->filter->bases() as $base) {
			$visited = [];
			$this->walk($base, $base, $now, $stamps, $visited);
		}

		return $stamps;
	}

	/**
	 * Follows symlinked directories, like path repositories in vendor;
	 * the visited real paths guard against symlink loops.
	 *
	 * @param array<string, Stamp> $stamps
	 * @param array<string, true> $visited
	 */
	private function walk(string $base, string $dir, int $now, array &$stamps, array &$visited): void
	{
		$real = realpath($dir);

		if ($real === false || isset($visited[$real])) {
			return;
		}

		$visited[$real] = true;
		$listing = $this->listing($base, $dir, $now);

		foreach ($listing['dirs'] ?? [] as $sub) {
			$this->walk($base, $sub, $now, $stamps, $visited);
		}

		foreach ($listing['files'] ?? [] as $file) {
			$stamp = FileStamp::of($file, $now);

			if ($stamp !== null) {
				$stamps[$file] = $stamp;
			}
		}
	}

	/** @return Listing|null */
	private function listing(string $base, string $dir, int $now): ?array
	{
		/** @var int|false $mtime */
		$mtime = ErrorTrap::run(static fn(): mixed => filemtime($dir));
		$cached = $this->listings[$base][$dir] ?? null;

		// A listing from the current second may miss entries added
		// later within that same second.
		if ($cached !== null && $cached['mtime'] === $mtime && $mtime < ($now - 1)) {
			return $cached;
		}

		/** @var list<string>|false $entries */
		$entries = ErrorTrap::run(static fn(): mixed => scandir($dir));

		if ($mtime === false || $entries === false) {
			unset($this->listings[$base][$dir]);

			return null;
		}

		$listing = ['mtime' => $mtime, 'dirs' => [], 'files' => []];

		foreach (array_diff($entries, ['.', '..']) as $entry) {
			$path = $dir === '.' ? $entry : rtrim($dir, '/') . "/{$entry}";

			if (is_dir($path)) {
				if ($this->filter->descends($base, $entry, $path)) {
					$listing['dirs'][] = $path;
				}
			} elseif ($this->filter->includes($base, $path)) {
				$listing['files'][] = $path;
			}
		}

		return $this->listings[$base][$dir] = $listing;
	}
}
