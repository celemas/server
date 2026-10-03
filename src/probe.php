<?php

declare(strict_types=1);

// Runs with FrankenPHP's embedded PHP (`frankenphp php-cli`) and prints its
// extensions as Composer names them, like `zend-opcache`; see FrankenProbe.
$names = array_map(
	static fn(string $name): string => str_replace(' ', '-', strtolower($name)),
	[...get_loaded_extensions(), ...get_loaded_extensions(zend_extensions: true)],
);

echo json_encode(array_values(array_unique($names)));
