# Celema Development Server

<!-- prettier-ignore-start -->
[![ci](https://codefloe.com/celema/server/badges/workflows/ci.yml/badge.svg?style=flat&logo=forgejo&logoColor=white&label=ci)](https://codefloe.com/celema/server/actions)
[![Software License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
<!-- prettier-ignore-end -->

Development server commands for PHP applications, built on `celema/console`.

> [!WARNING] This library is under active development, some of its features are still experimental and subject to change.

It provides two console command classes for local development:

- `Celema\Server\Server` runs the application with the PHP CLI's built-in server.
- `Celema\Server\FrankenPhp` runs it with the `frankenphp` executable from `PATH`.

Register either class with `celema/console` using a factory that supplies the application's public directory. Both commands support host, port, request-log filtering, and a `--watch` mode with live reload. The FrankenPHP command uses classic mode, not worker mode; its `--debug` option enables verbose Caddy logs. FrankenPHP embeds its own PHP runtime, extensions, and configuration rather than using the PHP CLI that starts the command.

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

## Live reload

With `--watch`, the command polls the watched files and tells open pages to reload when they change. Changed stylesheets are swapped in place without a full reload. The patterns come from the `watch` constructor argument, relative to the working directory; `--watch=<pattern>` overrides them. Directories named `node_modules`, `vendor`, or starting with a dot are skipped, unless a pattern's fixed path already points into them, like `vendor/acme/lib/**/*.php`. Symlinked directories are followed.

Pages opt in by including the live reload script. The command serves it on a separate port, ten times the public port or the next free port above it, and passes its URL to the application as the `CELEMA_LIVE_RELOAD` environment variable. It is only set while `--watch` runs, so the snippet renders nothing in production. Add it to your layout, before `</body>`:

```php
<?php if ($liveReload = getenv('CELEMA_LIVE_RELOAD')): ?>
	<script src="<?= htmlspecialchars($liveReload) ?>" defer></script>
<?php endif ?>
```

Open pages also reload once when they reconnect after the command restarts. If a watched file changes while no page is connected, the command says so, which usually means the snippet is missing.

## Request log protocol

The served application reports each handled request to the parent command as a structured `celema-request` line on stderr, which the command renders as a request log line. Applications can additionally report handled exceptions through `Celema\Server\Console` — inert unless the `CELEMA_CLI_SERVER` environment variable set by the dev server is present. `celema/core`'s error handler does this automatically when this package is installed.

## License

This project is licensed under the [MIT license](LICENSE.md).
