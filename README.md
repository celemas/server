# Celema Development Server

<!-- prettier-ignore-start -->
[![ci](https://codefloe.com/celema/server/badges/workflows/ci.yml/badge.svg?style=flat&logo=forgejo&logoColor=white&label=ci)](https://codefloe.com/celema/server/actions)
[![Software License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE.md)
<!-- prettier-ignore-end -->

Development server commands for PHP applications, built on `celema/console`, with request logging and live reload.

## Installation

```bash
composer require --dev celema/server
```

It requires PHP 8.5. The FrankenPHP command additionally needs the `frankenphp` executable on `PATH`; FrankenPHP embeds its own PHP runtime, extensions, and configuration rather than using the PHP CLI that starts the command. Live reload needs no Node.js or other external tools.

## Usage

The package provides two console commands:

- `Celema\Server\Server` (`server`) runs the application with the PHP CLI's built-in server.
- `Celema\Server\FrankenPhp` (`frankenphp`) runs it with FrankenPHP, in classic mode or, with `--worker`, in [worker mode](#worker-mode).

Register them with `celema/console`:

```php
#!/usr/bin/env php
<?php

use Celema\Console\Commands;
use Celema\Console\Runner;
use Celema\Server\FrankenPhp;
use Celema\Server\Server;

require __DIR__ . '/vendor/autoload.php';

$docroot = __DIR__ . '/public';
$watch = ['src/**/*.{php,css,js}', 'views/**/*.php'];
$commands = new Commands([
	new Server($docroot, port: 1973, watch: $watch),
	new FrankenPhp($docroot, port: 1973, watch: $watch),
]);

exit(new Runner($commands)->run());
```

Then start one of them, for example `php run server`. Both commands watch files and serve live reload by default. Pass `--no-watch` to disable both.

### Constructor arguments

Both commands take the same arguments:

| Argument | Default | Description |
| --- | --- | --- |
| `docroot` | required | The public directory. |
| `port` | `1983` | The default port. |
| `routePrefix` | `''` | A path prefix stripped from request paths, for applications mounted below a path. |
| `watch` | `'**/*.{php,js,css,sql,tpql}'` | Watch patterns for live reload, as a list or a comma-separated string. See [Live reload](#live-reload). |
| `executable` | `'php'` or `'frankenphp'` | The executable to run the backend with. |

### Options

| Option | Description |
| --- | --- |
| `-h`, `--host=<host>` | Host to bind to. Defaults to `localhost`. |
| `-p`, `--port=<port>` | Port to listen on. Defaults to the `port` argument. |
| `-f`, `--filter=<regex>` | Hides request log lines whose URL matches the regex, for example `--filter='#^/assets/#'`. |
| `-d`, `--debug` | `server`: sets `XDEBUG_SESSION`, so Xdebug debugs every request. `frankenphp`: enables verbose Caddy logs. |
| `-q`, `--quiet` | Reduces output: runs the PHP server with `-q`, hides FrankenPHP's startup banner, and hides live reload lines while pages are connected. |
| `-o`, `--open` | Opens the application in the default browser once it responds. |
| `--no-watch` | Disables file watching, live reload, and automatic worker restarts. |
| `--watch-files=<glob>` | Replaces the `watch` argument's patterns; repeat the option or separate patterns with commas. Does not enable watching when `--no-watch` is set. |
| `--reload-port=<port>` | Port for the live reload endpoint. Defaults to ten times the port, or the next free port above. |
| `--processes=<count>` | `server` only: serves requests concurrently with the given number of PHP server processes, for pages that load many PHP-generated resources at once. Defaults to one process. Not available on Windows. |
| `--worker[=<count>]` | `frankenphp` only: keeps the application in memory with FrankenPHP workers. Defaults to one worker; an explicit count must be a positive integer. See [Worker mode](#worker-mode). |

## Routing

With the built-in PHP server, requests for existing files in the public directory are handled by the server directly: PHP files run, others are served as they are, and a directory with an `index.html` serves that file. Every other request goes to `index.php` in the public directory, the front controller. FrankenPHP routes requests with its own PHP server defaults. Both commands strip the `routePrefix` from request paths.

## PHP settings

Both commands make OPcache check for changed files on every request, so a request right after saving a file never runs its previous version. They add the `src/ini` directory of this package to `PHP_INI_SCAN_DIR` for that, after the directories PHP scans anyway.

## Live reload

By default, the command polls the watched files and tells open pages to update when they change:

- Changed stylesheets are swapped in place, including the stylesheets they import.
- Other changes, such as code or templates, morph the page into a freshly rendered copy with [Idiomorph](https://github.com/bigskysoftware/idiomorph), which the command serves itself. The scroll position, focus, and form input the user changed are kept, and scripts do not run again. The page reloads instead when its scripts changed, inline ones included, when a form posted to it without a redirect, or when its URL no longer answers with an HTML page, for example after a redirect.
- Changed scripts reload the page.

Pages that a morph would break, like pages whose scripts render markup or keep state in the DOM, opt out with a meta tag; they reload for every change other than stylesheets:

```html
<meta name="celema-live-reload" content="reload" />
```

After a morph, the script dispatches a `celema:morphed` event on the document, so pages can set up behaviors for new markup.

Patterns are relative to the working directory. `**` matches across directories, `*` and `?` within one path segment, and braces list alternatives, like `*.{php,js}`. A pattern starting with `!` excludes matching files, for example `['src/**/*.php', '!src/cache/**']`; a negated pattern that covers a whole directory, like `!src/cache/**` or `!src/cache/`, skips it while scanning. Directories named `node_modules`, `vendor`, or starting with a dot are skipped, unless a pattern's fixed path already points into them, like `vendor/acme/lib/**/*.php`. Symlinked directories are followed. At startup, the command prints how many files it watches, or warns when the patterns match none.

Pages opt in by including the live reload script. The command serves it on a separate port and passes its URL to the application as the `CELEMA_LIVE_RELOAD` environment variable. It is only set while the development server serves live reload, so the snippet renders nothing with `--no-watch` or in production. Add it to your layout, before `</body>`:

```php
<?php if ($liveReload = getenv('CELEMA_LIVE_RELOAD')): ?>
	<script src="<?= htmlspecialchars($liveReload) ?>" defer></script>
<?php endif ?>
```

The URL uses the `--host` address, with `localhost` for wildcard addresses, which suits a browser on the same machine. For other devices, virtual machines, or containers, bind a wildcard such as `--host=0.0.0.0` and replace the URL's host with the host the page was requested under. With `localhost`, the script is served on both loopback addresses, so local host names resolving to either work too. Pages served over HTTPS cannot load the script, because it is only served over HTTP.

Pass `--no-watch` to skip file polling and the live reload endpoint entirely. Omitting the script from a layout only disables automatic updates for those pages; file watching and worker restarts still run.

Open pages also reload once when they reconnect after the command restarts. If a watched file changes while no page is connected, the command says so, which usually means the snippet is missing.

## Worker mode

`frankenphp --worker` serves the application with one [FrankenPHP worker](https://frankenphp.dev/docs/worker/) that runs the document root's `index.php` and keeps the application in memory between requests, as a production worker does. The front controller has to support worker mode, for example through Celema core's `App::serve()`.

A worker only sees code changes after a restart. With the default file watching enabled, changes to watched files other than stylesheets and scripts restart all workers through FrankenPHP's admin API before pages update. The admin API listens on a random loopback port for that and is not reachable from other machines. FrankenPHP's own `watch` directive is not used, as it does not follow symlinked package directories such as path repositories in `vendor`.

By default, one worker handles requests one after another, which keeps restarts quick and the request log in order. Pass a positive integer, such as `frankenphp --worker=8`, to run multiple workers and handle PHP requests concurrently. Each worker keeps its own application instance in memory.

For uninterrupted load or memory-leak testing, use `frankenphp --worker=8 --no-watch`. Workers still run, but file watching, live reload, and the admin API are disabled. Restart the command manually to pick up application code changes.

## Request log protocol

The served application reports each handled request to the parent command as a structured `celema-request` line on stderr, which the command renders as a request log line. With the built-in PHP server, the log shows the status of the PSR-7 response the front controller returns, or otherwise the status the script set.

Applications can additionally report handled exceptions through `Celema\Server\Console`, which is inert unless the `CELEMA_CLI_SERVER` environment variable set by the dev server is present. `celema/core`'s error handler does this automatically when this package is installed.

## Platform support

The commands are developed and tested on macOS and Linux. Windows has basic support, like finding executables with `where`, but is untested.

## License

This project is licensed under the [MIT license](LICENSE.md).
