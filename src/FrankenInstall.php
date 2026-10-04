<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Arg;
use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;

/** @api */
#[Command('frankenphp:install', 'Download FrankenPHP into the cache all projects share', group: 'FrankenPHP')]
#[Arg('version', 'The version to install, like 1.12.7. Defaults to the latest release.', optional: true)]
final class FrankenInstall
{
	public function __invoke(Args $args, Io $io): int
	{
		$argument = $args->positional(0);
		$version = $argument === null ? null : FrankenCache::version($argument);

		if ($argument !== null && $version === null) {
			return self::fail($io, "Invalid FrankenPHP version '{$argument}'; use a version like 1.12.7.");
		}

		$cache = FrankenCache::locate();

		if (is_string($cache)) {
			return self::fail($io, $cache);
		}

		// An installed version needs no lookup.
		if ($version !== null && $cache->has($version)) {
			return self::installed($cache, $version, $io);
		}

		$releases = FrankenReleases::create();

		if (is_string($releases)) {
			return self::fail($io, $releases);
		}

		$release = $version === null ? $releases->latest() : $releases->tag($version);

		if (is_string($release)) {
			return self::fail($io, $release);
		}

		if ($cache->has($release->version)) {
			return self::installed($cache, $release->version, $io);
		}

		return $this->install($cache, $release, $io);
	}

	private static function installed(FrankenCache $cache, string $version, Io $io): int
	{
		$io->info("FrankenPHP {$version} is already installed: {$cache->binary($version)}");

		return 0;
	}

	private static function fail(Io $io, string $message): int
	{
		$io->error($message);

		return 1;
	}

	private function install(FrankenCache $cache, FrankenRelease $release, Io $io): int
	{
		$interrupt = Interrupt::catch();

		try {
			$installer = new FrankenInstaller($cache, $io, stream_isatty(STDOUT), $interrupt);
			$error = $installer->install($release);
		} finally {
			$interrupt->release();
		}

		if ($error !== null) {
			$io->error($error);

			return 1;
		}

		$io->success("Installed FrankenPHP {$release->version}: {$cache->binary($release->version)}");
		$path = Executable::find('frankenphp');

		// Without a pinned version, a FrankenPHP on PATH takes precedence.
		if ($path !== null) {
			$io->info(
				"FrankenPHP runs {$path} from PATH, unless a run script pins this version: "
					. "new Server(\$docroot, version: '{$release->version}')",
			);
		}

		return 0;
	}
}
