<?php

declare(strict_types=1);

namespace Celema\Server;

/** @internal */
final class Executable
{
	/**
	 * The path of an executable: a name is looked up on PATH, a path
	 * must exist. Returns null when it is not found.
	 */
	public static function find(string $name): ?string
	{
		$windows = DIRECTORY_SEPARATOR === '\\';

		if (str_contains($name, '/') || $windows && str_contains($name, '\\')) {
			return self::runnable($name) ? $name : null;
		}

		$path = getenv('PATH');
		// Windows finds programs by the extensions PATHEXT lists.
		$pathExt = getenv('PATHEXT');
		$extensions = $windows ? ['', ...explode(';', is_string($pathExt) ? $pathExt : '.EXE;.BAT;.CMD')] : [''];

		foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $dir) {
			if ($dir === '') {
				continue;
			}

			foreach ($extensions as $extension) {
				$file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;

				if (self::runnable($file)) {
					return $file;
				}
			}
		}

		return null;
	}

	private static function runnable(string $file): bool
	{
		return is_file($file) && is_executable($file);
	}
}
