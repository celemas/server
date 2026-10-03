<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * Installs a FrankenPHP release into the cache: downloads its build next
 * to the final location, checks it against the published checksum and
 * runs it once, then moves it into place. A failed or canceled install
 * leaves nothing behind.
 *
 * @internal
 */
final readonly class FrankenInstaller
{
	private const int PROGRESS_INTERVAL = 100_000_000;

	public function __construct(
		private FrankenCache $cache,
		private Io $io,
		private bool $terminal,
		private ?Interrupt $interrupt = null,
	) {}

	/** Returns an error message, or null once installed. */
	public function install(FrankenRelease $release): ?string
	{
		$binary = $this->cache->binary($release->version);
		$dir = dirname($binary);

		if (!is_dir($dir)) {
			ErrorTrap::run(static fn(): mixed => mkdir($dir, 0o755, true));
		}

		if (!is_dir($dir)) {
			return "Could not create {$dir}.";
		}

		$this->io->echoln(
			"Downloading FrankenPHP {$release->version} <dim>({$release->asset}, {$release->megabytes()})</dim>",
		);
		$part = "{$binary}." . (string) getmypid() . '.part';

		try {
			$error = FrankenDownload::run($release, $part, $this->progress($release));
			$this->done();
			$error ??= $this->verify($release, $part);

			if ($error !== null) {
				return $error;
			}

			return ErrorTrap::run(static fn(): mixed => rename($part, $binary)) === true
				? null
				: "Could not move the download to {$binary}.";
		} finally {
			if (is_file($part)) {
				unlink($part);
			}
		}
	}

	private function verify(FrankenRelease $release, string $file): ?string
	{
		if ($release->sha256 === null) {
			$this->io->warn("FrankenPHP {$release->version} has no published checksum; the download is not verified.");
		} elseif (hash_file('sha256', $file) !== $release->sha256) {
			return "The download of FrankenPHP {$release->version} does not match its published checksum.";
		}

		chmod($file, 0o755);
		// Catches a build for another architecture, or one the system refuses to run.
		$version = Process::output([$file, 'version'], timeout: 10.0) ?? '';

		if (preg_match('/^FrankenPHP v?' . preg_quote($release->version, '/') . '\s/', $version . ' ') !== 1) {
			return "The downloaded FrankenPHP {$release->version} does not run on this system.";
		}

		return null;
	}

	/** @return callable(int): bool */
	private function progress(FrankenRelease $release): callable
	{
		$shown = 0;

		return function (int $bytes) use ($release, &$shown): bool {
			$now = (int) hrtime(true);

			if ($this->terminal && (($now - $shown) >= self::PROGRESS_INTERVAL || $bytes === $release->size)) {
				$shown = $now;
				$percent = $release->size > 0 ? (int) floor(($bytes * 100) / $release->size) : 0;
				$this->io->echo(sprintf("\r%3d%% %7.1f MB", $percent, $bytes / 1_000_000));
			}

			return !($this->interrupt?->received() ?? false);
		};
	}

	private function done(): void
	{
		if ($this->terminal) {
			$this->io->echoln('');
		}
	}
}
