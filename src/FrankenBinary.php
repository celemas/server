<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * The FrankenPHP executable to run. It is, in this order:
 *
 * 1. The configured one: an executable, or a version that pins the one
 *    from the cache. Another FrankenPHP never stands in for a pinned
 *    version, which would quietly ignore the pin.
 * 2. `frankenphp` on PATH.
 * 3. The newest version in the cache.
 *
 * When the pinned version, or any FrankenPHP, is missing, it is
 * downloaded once the user agrees in a terminal; never otherwise.
 *
 * @internal
 */
final readonly class FrankenBinary
{
	private function __construct(
		public string $path,
	) {}

	/**
	 * Whether a configured FrankenPHP names a version rather than an
	 * executable: it starts with a digit, or with `v` and a digit.
	 */
	public static function pins(string $frankenphp): bool
	{
		return preg_match('/^v?\d/', $frankenphp) === 1;
	}

	public static function resolve(
		?string $frankenphp,
		Io $io,
		bool $interactive,
		?Interrupt $interrupt = null,
	): self|string {
		if ($frankenphp !== null && self::pins($frankenphp)) {
			return self::pinned($frankenphp, $io, $interactive, $interrupt);
		}

		if ($frankenphp !== null) {
			$path = Executable::find($frankenphp);

			return $path === null ? "The FrankenPHP executable '{$frankenphp}' was not found." : new self($path);
		}

		$path = Executable::find('frankenphp');

		if ($path !== null) {
			return new self($path);
		}

		$cache = FrankenCache::locate();

		if (is_string($cache)) {
			return "FrankenPHP was not found on PATH. {$cache}";
		}

		$newest = $cache->newest();

		if ($newest !== null) {
			return new self($cache->binary($newest));
		}

		$missing = 'FrankenPHP was not found.';

		if (!$interactive) {
			return "{$missing} Put frankenphp on PATH, or run the command in a terminal to download it.";
		}

		if (!$io->confirm("{$missing} Download the latest release to {$cache->display()}?")) {
			return "{$missing} Put frankenphp on PATH, or download it with the frankenphp:install command.";
		}

		return self::install($cache, null, $io, $interrupt);
	}

	private static function pinned(string $version, Io $io, bool $interactive, ?Interrupt $interrupt): self|string
	{
		$pinned = FrankenCache::version($version);

		if ($pinned === null) {
			return "Invalid FrankenPHP version '{$version}'; use a version like 1.12.7.";
		}

		$cache = FrankenCache::locate();

		if (is_string($cache)) {
			return $cache;
		}

		if ($cache->has($pinned)) {
			return new self($cache->binary($pinned));
		}

		$missing = "FrankenPHP {$pinned} is not installed.";

		if (!$interactive) {
			return "{$missing} Run the command in a terminal to download it.";
		}

		if (!$io->confirm("{$missing} Download it to {$cache->display()}?")) {
			return "{$missing} Download it with the frankenphp:install command.";
		}

		return self::install($cache, $pinned, $io, $interrupt);
	}

	private static function install(FrankenCache $cache, ?string $version, Io $io, ?Interrupt $interrupt): self|string
	{
		$releases = FrankenReleases::create();

		if (is_string($releases)) {
			return $releases;
		}

		$release = $version === null ? $releases->latest() : $releases->tag($version);

		if (is_string($release)) {
			return $release;
		}

		$error = new FrankenInstaller($cache, $io, true, $interrupt)->install($release);

		return $error ?? new self($cache->binary($release->version));
	}
}
