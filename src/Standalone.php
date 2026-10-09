<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;
use Celema\Console\Runner;

/**
 * The `cserve` command line: the commands of this package without
 * a run script of one's own, for any PHP application with a public
 * directory. Without a command, it runs the `server` command, and a
 * server name as the command, like `cserve frankenphp`, runs that one.
 *
 * @internal
 */
final class Standalone
{
	private const array DOCROOTS = ['public', 'web', 'htdocs'];

	/** @param list<string> $argv */
	public static function run(array $argv, string $cwd, ?Io $io = null): int
	{
		$io ??= new Io();
		$argv = self::argv($argv);
		$docroot = self::docroot($cwd);

		if (array_slice($argv, offset: 1, length: 1) === ['server']) {
			self::announce($docroot, $cwd, $io);
		}

		return new Runner([new Server($docroot), new FrankenInstall(), new Reload()], $io)->run($argv);
	}

	/**
	 * Runs the server command without a command, with only options, or
	 * with a server name. `-h` means `--host` there, so a leading one asks
	 * for help instead.
	 *
	 * @param list<string> $argv
	 * @return list<string>
	 */
	public static function argv(array $argv): array
	{
		$script = $argv[0] ?? 'cserve';
		$first = $argv[1] ?? null;

		return array_values(match (true) {
			$first === '--help' || $first === '-h' => [$script, 'help', ...array_slice($argv, 2)],
			$first === null || str_starts_with($first, '-') || in_array($first, ProjectConfig::SERVERS, true) => [
				$script,
				'server',
				...array_slice($argv, 1),
			],
			default => $argv,
		});
	}

	/**
	 * The first of the usual public directories with a front controller,
	 * or else the working directory itself.
	 */
	public static function docroot(string $cwd): string
	{
		foreach (self::DOCROOTS as $dir) {
			$path = $cwd . DIRECTORY_SEPARATOR . $dir;

			if (is_file($path . DIRECTORY_SEPARATOR . 'index.php')) {
				return $path;
			}
		}

		return $cwd;
	}

	private static function announce(string $docroot, string $cwd, Io $io): void
	{
		if ($docroot !== $cwd) {
			$io->line('<dim>Public directory: ' . $io->escape(basename($docroot)) . '</dim>');

			return;
		}

		// The whole project is served, including files like .env.
		$io->warn(
			'No public/, web/, or htdocs/ directory with an index.php found; serving the whole working directory.',
		);
	}
}
