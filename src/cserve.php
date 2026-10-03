<?php

declare(strict_types=1);

namespace Celema\Server;

// Runs the commands of celema/server without a run script, see Standalone;
// required by bin/cserve, whose Composer proxy names the project's
// autoloader.
/** @var list<string|null> $autoloads */
$autoloads = [
	// @mago-expect lint:no-global
	$GLOBALS['_composer_autoload_path'] ?? null,
	dirname(__DIR__, 3) . '/autoload.php',
	dirname(__DIR__) . '/vendor/autoload.php',
];

foreach ($autoloads as $autoload) {
	if ($autoload !== null && is_file($autoload)) {
		/** @psalm-suppress UnresolvableInclude */
		require $autoload;

		/** @var list<string> $argv */
		exit(Standalone::run($argv, (string) getcwd()));
	}
}

fwrite(STDERR, "cserve: Composer's autoloader was not found; install the package with Composer.\n");

exit(1);
