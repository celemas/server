<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Detects changes to the watched files by polling their stat data;
 * native file events would need an extension or an external tool.
 *
 * @internal
 *
 * @psalm-import-type Stamp from FileStamp
 */
final class FileWatch
{
	private readonly FileScan $scan;

	/** @var array<string, Stamp> */
	private array $stamps;

	/** @param list<string> $patterns */
	public function __construct(array $patterns)
	{
		$globs = [];

		foreach ($patterns as $pattern) {
			$glob = WatchGlob::compile($pattern);
			$globs[$glob->base][] = $glob;
		}

		$this->scan = new FileScan($globs);
		$this->stamps = $this->scan->run();
	}

	/**
	 * Returns the paths added, removed, or modified since the last call.
	 *
	 * @return list<string>
	 */
	public function changes(): array
	{
		$stamps = $this->scan->run();
		$changed = [];

		foreach ($stamps as $path => $stamp) {
			$previous = $this->stamps[$path] ?? null;

			if ($previous === null || FileStamp::modified($previous, $stamp)) {
				$changed[] = $path;
			}
		}

		foreach (array_keys(array_diff_key($this->stamps, $stamps)) as $path) {
			$changed[] = $path;
		}

		$this->stamps = $stamps;

		return $changed;
	}
}
