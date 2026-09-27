<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * The stat data FileWatch compares to detect a changed file.
 *
 * @internal
 *
 * @psalm-type Stamp = array{mtime: int, size: int, hash: ?string}
 */
final class FileStamp
{
	/**
	 * Modification times only have second precision. Files modified
	 * within the last seconds also get a content hash, so a second save
	 * in the same second with an unchanged size is not missed.
	 *
	 * @return Stamp|null
	 */
	public static function of(string $path, int $now): ?array
	{
		/** @var array{mtime: int, size: int}|false $stat */
		$stat = ErrorTrap::run(static fn(): mixed => stat($path));

		if ($stat === false) {
			return null;
		}

		$hash = null;

		if ($stat['mtime'] >= ($now - 2)) {
			/** @var string|false $hash */
			$hash = ErrorTrap::run(static fn(): mixed => hash_file('xxh3', $path));
			$hash = $hash === false ? null : $hash;
		}

		return ['mtime' => $stat['mtime'], 'size' => $stat['size'], 'hash' => $hash];
	}

	/**
	 * @param Stamp $previous
	 * @param Stamp $current
	 */
	public static function modified(array $previous, array $current): bool
	{
		if ($previous['mtime'] !== $current['mtime'] || $previous['size'] !== $current['size']) {
			return true;
		}

		// A hash on both sides means a recent file; compare contents.
		return $previous['hash'] !== null && $current['hash'] !== null && $previous['hash'] !== $current['hash'];
	}
}
