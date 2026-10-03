<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * The FrankenPHP executable to run. It is, in this order:
 *
 * 1. The configured executable.
 * 2. The pinned version from the cache. Another FrankenPHP never stands
 *    in for it, which would quietly ignore the pin.
 * 3. `frankenphp` on PATH.
 * 4. The newest version in the cache.
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

	public static function resolve(
		?string $executable,
		?string $version,
		Io $io,
		bool $interactive,
		?Interrupt $interrupt = null,
	): self|string {
		if ($executable !== null) {
			$path = Executable::find($executable);

			return $path === null ? "The FrankenPHP executable '{$executable}' was not found." : new self($path);
		}

		if ($version !== null) {
			return self::pinned($version, $io, $interactive, $interrupt);
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
