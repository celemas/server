<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * The project's own PHP settings: the ini files in `.cserve/php/` of the
 * working directory, read after the system's settings and this package's.
 * The directory is added to PHP_INI_SCAN_DIR as it is, so PHP reads every
 * `*.ini` file in it, in alphabetical order.
 *
 * @internal
 */
final readonly class ProjectIni
{
	public const string DIR = '.cserve/php';

	/** @param non-empty-list<string> $files */
	private function __construct(
		public string $dir,
		public array $files,
	) {}

	/** The project's settings; null without ini files to read. */
	public static function load(string $cwd): ?self
	{
		$dir = $cwd . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIR);
		$found = glob($dir . DIRECTORY_SEPARATOR . '*.ini');
		$files = $found === false ? [] : array_values(array_filter($found, is_file(...)));

		return $files === [] ? null : new self($dir, array_map(basename(...), $files));
	}

	public function announce(Io $io): void
	{
		$files = implode(', ', array_map(static fn(string $file): string => self::DIR . "/{$file}", $this->files));
		$io->line('<dim>PHP settings: ' . $io->escape($files) . '</dim>');
	}
}
