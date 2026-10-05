<?php

declare(strict_types=1);

// Runs a command as the leader of a new process group, so the dev server
// can stop it together with every process it starts, like the forked
// processes of the PHP server; see ProcessGroup. Runs without ini files, and
// `--check` reports whether this PHP can do it: `ok`, or else the missing
// extensions, like `posix`, which some distributions build as shared
// modules that only ini files load.
if (($argv[1] ?? '') === '--check') {
	$missing = array_keys(array_filter(
		['pcntl' => function_exists('pcntl_exec'), 'posix' => function_exists('posix_setpgid')],
		static fn(bool $available): bool => !$available,
	));
	echo $missing === [] ? 'ok' : implode(' ', $missing);

	exit(0);
}

if (!posix_setpgid(0, 0)) {
	fwrite(STDERR, "Could not start a process group for {$argv[1]}\n");
}

pcntl_exec($argv[1], array_slice($argv, 2));
fwrite(STDERR, "Could not run {$argv[1]}\n");

exit(127);
