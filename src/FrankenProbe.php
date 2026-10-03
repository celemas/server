<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Compares the extensions a project requires in its `composer.json` with
 * those of FrankenPHP's embedded PHP. A static FrankenPHP build cannot
 * load further extensions, so a missing one would only show as an error
 * in the middle of a request.
 *
 * @internal
 */
final class FrankenProbe
{
	/**
	 * The required extensions FrankenPHP lacks, like `ext-intl`. Empty
	 * when the project requires none, or when the probe fails.
	 *
	 * @return list<string>
	 */
	public static function missing(Setup $setup, string $dir): array
	{
		$required = self::required($dir);

		if ($required === []) {
			return [];
		}

		/** @var mixed $loaded */
		$loaded = json_decode(Process::output($setup->frankenPhpProbeCommand()) ?? '', true);

		if (!is_array($loaded)) {
			return [];
		}

		return array_values(array_map(
			static fn(string $name): string => "ext-{$name}",
			array_diff($required, $loaded),
		));
	}

	/**
	 * The extensions the project's `composer.json` requires, development
	 * dependencies included, as Composer names them.
	 *
	 * @return list<string>
	 */
	public static function required(string $dir): array
	{
		$file = $dir . DIRECTORY_SEPARATOR . 'composer.json';
		/** @var mixed $composer */
		$composer = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

		if (!is_array($composer)) {
			return [];
		}

		$extensions = [];

		foreach (['require', 'require-dev'] as $section) {
			/** @var mixed $packages */
			$packages = $composer[$section] ?? [];

			if (!is_array($packages)) {
				continue;
			}

			foreach (array_keys($packages) as $package) {
				if (is_string($package) && str_starts_with(strtolower($package), 'ext-')) {
					$extensions[] = strtolower(substr($package, 4));
				}
			}
		}

		return array_values(array_unique($extensions));
	}
}
