# Celema Development Server

<!-- prettier-ignore-start -->
[![ci](https://codeberg.org/celema/server/badges/workflows/ci.yml/badge.svg?style=flat&logo=codeberg&logoColor=white&label=ci)](https://codeberg.org/celema/server/actions)
[![Software License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
<!-- prettier-ignore-end -->

Development server commands for PHP applications, built on `celema/console`.

> [!WARNING] This library is under active development, some of its features are still experimental and subject to change.

It provides two console command classes for local development:

- `Celema\Server\Server` runs the application with the PHP CLI's built-in server.
- `Celema\Server\FrankenPhp` runs it with the `frankenphp` executable from `PATH`.

Register either class with `celema/console` using a factory that supplies the application's public directory. Both commands support host, port, request-log filtering, and BrowserSync-backed `--watch` mode. The FrankenPHP command uses classic mode, not worker mode; its `--debug` option enables verbose Caddy logs. FrankenPHP embeds its own PHP runtime, extensions, and configuration rather than using the PHP CLI that starts the command.

```php
#!/usr/bin/env php
<?php

use Celema\Console\Commands;
use Celema\Console\Runner;
use Celema\Server\FrankenPhp;
use Celema\Server\Server;

require __DIR__ . '/vendor/autoload.php';

$docroot = __DIR__ . '/public';
$watch = ['src/**/*.{php,css,js}'];
$commands = new Commands([
	new Server($docroot, port: 1973, watch: $watch),
	new FrankenPhp($docroot, port: 1973, watch: $watch),
]);

exit(new Runner($commands)->run());
```

## Request log protocol

The served application reports each handled request to the parent command as a structured `celema-request` line on stderr, which the command renders as a request log line. Applications can additionally report handled exceptions through `Celema\Server\Console` — inert unless the `CELEMA_CLI_SERVER` environment variable set by the dev server is present. `celema/core`'s error handler does this automatically when this package is installed.

## License

This project is licensed under the [MIT license](LICENSE.md).
