<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * The managed FrankenPHP binaries: one directory per version in a cache
 * that all projects share, like
 * `~/Library/Caches/celema/frankenphp/1.12.7/frankenphp` on macOS.
 * A binary takes up about 180 MB, so projects do not keep their own.
 *
 * `CELEMA_FRANKENPHP_DIR` overrides the location.
 *
 * @internal
 */
final readonly class FrankenCache
{
	public function __construct(
		public string $dir,
	) {}

	/** The cache of this platform, or an error message. */
	public static function locate(): self|string
	{
		$dir = getenv('CELEMA_FRANKENPHP_DIR');

		if (is_string($dir) && $dir !== '') {
			return new self(rtrim($dir, '/\\'));
		}

		if (DIRECTORY_SEPARATOR === '\\') {
			$local = getenv('LOCALAPPDATA');

			return is_string($local) && $local !== ''
				? new self("{$local}\\celema\\frankenphp")
				: 'Cannot locate the FrankenPHP cache: LOCALAPPDATA is not set.';
		}

		$home = getenv('HOME');

		if (!is_string($home) || $home === '') {
			return 'Cannot locate the FrankenPHP cache: HOME is not set.';
		}

		if (PHP_OS_FAMILY === 'Darwin') {
			return new self("{$home}/Library/Caches/celema/frankenphp");
		}

		// The XDG specification ignores relative paths.
		$cache = getenv('XDG_CACHE_HOME');
		$base = is_string($cache) && str_starts_with($cache, '/') ? rtrim($cache, '/') : "{$home}/.cache";

		return new self("{$base}/celema/frankenphp");
	}

	/**
	 * Normalizes a version like `v1.12.7` to `1.12.7`. Returns null for
	 * anything else, which also keeps versions from naming other paths.
	 */
	public static function version(string $version): ?string
	{
		$version = str_starts_with($version, 'v') ? substr($version, 1) : $version;

		return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/D', $version) === 1 ? $version : null;
	}

	/** The directory for messages, with `~` for the home directory. */
	public function display(): string
	{
		$home = getenv('HOME');

		return is_string($home) && $home !== '' && str_starts_with($this->dir, $home . '/')
			? '~' . substr($this->dir, strlen($home))
			: $this->dir;
	}

	public function binary(string $version): string
	{
		return $this->dir . DIRECTORY_SEPARATOR . $version . DIRECTORY_SEPARATOR . 'frankenphp';
	}

	public function has(string $version): bool
	{
		$binary = $this->binary($version);

		return is_file($binary) && is_executable($binary);
	}

	/**
	 * The installed versions, newest first.
	 *
	 * @return list<string>
	 */
	public function installed(): array
	{
		$entries = is_dir($this->dir) ? scandir($this->dir) : false;
		$versions = array_values(array_filter(
			$entries === false ? [] : $entries,
			fn(string $entry): bool => self::version($entry) === $entry && $this->has($entry),
		));
		usort($versions, static fn(string $a, string $b): int => version_compare($b, $a));

		return $versions;
	}

	public function newest(): ?string
	{
		return $this->installed()[0] ?? null;
	}
}
