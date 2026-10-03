<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * The project's own PHP settings: a `cserve.ini` in the working directory,
 * read after the system's settings and this package's. PHP_INI_SCAN_DIR
 * only takes directories, and scanning the project directory would load
 * every ini file in it, so the file is copied into a temporary directory
 * of its own. A copy suffices, as PHP reads ini files only at startup.
 *
 * @internal
 */
final readonly class ProjectIni
{
	public const string FILE = 'cserve.ini';

	private function __construct(
		public string $dir,
	) {}

	/** Copies the project's file, or returns an error message; null when there is none. */
	public static function load(string $cwd): self|string|null
	{
		$file = $cwd . DIRECTORY_SEPARATOR . self::FILE;

		if (!is_file($file)) {
			return null;
		}

		$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'celema-ini-' . bin2hex(random_bytes(8));
		$ini = new self($dir);
		$copied = (bool) ErrorTrap::run(
			static fn(): bool => mkdir($dir, 0o700) && copy($file, $dir . DIRECTORY_SEPARATOR . self::FILE),
			$error,
		);

		if (!$copied) {
			$ini->remove();

			return 'Failed to load ' . self::FILE . ($error === null ? '.' : ": {$error}");
		}

		return $ini;
	}

	public function announce(Io $io): void
	{
		$io->echoln('<dim>PHP settings: ' . self::FILE . '</dim>');
	}

	public function remove(): void
	{
		$file = $this->dir . DIRECTORY_SEPARATOR . self::FILE;

		if (is_file($file)) {
			unlink($file);
		}

		if (is_dir($this->dir)) {
			rmdir($this->dir);
		}
	}
}
