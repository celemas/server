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

It requires PHP 8.5. The FrankenPHP command uses `frankenphp` from `PATH`, or downloads FrankenPHP once you agree; see [Installing FrankenPHP](#installing-frankenphp). FrankenPHP embeds its own PHP runtime, extensions, and configuration rather than using the PHP CLI that starts the command. Live reload needs no Node.js or other external tools.

## Usage

The package provides these console commands:

- `Celema\Server\Server` (`server`) runs the application with the PHP CLI's built-in server.
- `Celema\Server\FrankenPhp` (`frankenphp`) runs it with FrankenPHP, in classic mode or, with `--worker`, in [worker mode](#worker-mode).
- `Celema\Server\FrankenInstall` (`frankenphp:install`) downloads FrankenPHP; see [Installing FrankenPHP](#installing-frankenphp).
- `Celema\Server\Reload` (`reload`) serves live reload for an application that runs elsewhere, like in a container; see [Live reload for other servers](#live-reload-for-other-servers).

Register them with `celema/console`:

```php
#!/usr/bin/env php
<?php

use Celema\Console\Commands;
use Celema\Console\Runner;
use Celema\Server\FrankenInstall;
use Celema\Server\FrankenPhp;
use Celema\Server\Server;

require __DIR__ . '/vendor/autoload.php';

$docroot = __DIR__ . '/public';
$watch = ['src/**/*.{php,css,js}', 'views/**/*.php'];
$commands = new Commands([
	new Server($docroot, port: 1973, watch: $watch),
	new FrankenPhp($docroot, port: 1975, watch: $watch),
	new FrankenInstall(),
]);

exit(new Runner($commands)->run());
```

Then start one of them, for example `php run server`. Both commands watch files and serve live reload by default. Pass `--no-watch` to disable both.

### Without a run script

The package also installs `vendor/bin/cserve`, which runs these commands for any PHP application, without a run script of its own:

```bash
vendor/bin/cserve
vendor/bin/cserve frankenphp --worker
vendor/bin/cserve reload
```

It serves the first of `public/`, `web/`, or `htdocs/` that contains an `index.php`, or else, with a warning, the whole working directory. Without a command, or with only options, it runs `server`; `vendor/bin/cserve --help` lists the commands. The commands use their default arguments; a run script remains the way to set watch patterns, a route prefix, a FrankenPHP version, or companion processes.

### Constructor arguments

Both server commands take these arguments:

| Argument | Default | Description |
| --- | --- | --- |
| `docroot` | required | The public directory. |
| `port` | `2130` | The default port. |
| `routePrefix` | `''` | A path prefix stripped from request paths, for applications mounted below a path. |
| `watch` | `'**/*.{php,js,css,sql,tpql}'` | Watch patterns for live reload, as a list or a comma-separated string. See [Live reload](#live-reload). |
| `executable` | `'php'`, or none for FrankenPHP | The executable to run the backend with. For FrankenPHP, it replaces the [lookup](#installing-frankenphp). |
| `companions` | `[]` | Processes to run alongside the server, like asset watchers. See [Companion processes](#companion-processes). |
| `version` | none | `FrankenPhp` only: the FrankenPHP version to run from the shared cache, like `'1.12.7'`. See [Installing FrankenPHP](#installing-frankenphp). |

### Options

| Option | Description |
| --- | --- |
| `-h`, `--host=<host>` | Host to bind to. Defaults to `localhost`. |
| `-p`, `--port=<port>` | Port to listen on. Defaults to the `port` argument. |
| `-f`, `--filter=<regex>` | Hides request log lines whose URL matches the regex, for example `--filter='#^/assets/#'`. |
| `-d`, `--debug` | `server`: sets `XDEBUG_SESSION`, so Xdebug debugs every request. `frankenphp`: enables verbose Caddy logs. |
| `-q`, `--quiet` | Reduces output: runs the PHP server with `-q` and hides live reload lines while pages are connected. |
| `-o`, `--open` | Opens the application in the default browser once it responds. |
| `--no-watch` | Disables file watching, live reload, and automatic worker restarts. |
| `--no-companions` | Does not start the configured [companion processes](#companion-processes). |
| `--watch-files=<glob>` | Replaces the `watch` argument's patterns; repeat the option or separate patterns with commas. Does not enable watching when `--no-watch` is set. |
| `--reload-port=<port>` | Port for the live reload endpoint. Defaults to ten times the port, or the next free port above. |
| `--processes=<count>` | `server` only: serves requests concurrently with the given number of PHP server processes, for pages that load many PHP-generated resources at once. Defaults to one process. Not available on Windows. |
| `--worker[=<count>]` | `frankenphp` only: keeps the application in memory with FrankenPHP workers. Defaults to one worker; an explicit count must be a positive integer. See [Worker mode](#worker-mode). |

Both commands check whether the application port is available before starting. A busy port is an error that suggests stopping another running server or choosing a different `--port`; they do not switch to another application port. The automatically selected live reload port tries its initial candidate and up to 100 higher ports, stopping at 65535. An explicit `--reload-port` must be available.

## Routing

With the built-in PHP server, requests for existing files in the public directory are handled by the server directly: PHP files run, others are served as they are, and a directory with an `index.html` serves that file. Every other request goes to `index.php` in the public directory, the front controller. FrankenPHP routes requests with the defaults of its `php_server` directive, through a configuration the command generates, and compresses responses with Zstandard or gzip. Both commands strip the `routePrefix` from request paths.

Both commands start by printing the address they serve, with the PHP version for the built-in server. Ctrl+C, or a SIGTERM sent to the command, stops the server and removes the temporary FrankenPHP configuration; a second signal stops the command at once. This needs the `pcntl` extension in the PHP CLI that runs the command.

## PHP settings

Both commands make OPcache check for changed files on every request, so a request right after saving a file never runs its previous version. They add the `src/ini` directory of this package to `PHP_INI_SCAN_DIR` for that, after the directories PHP scans anyway.

For settings of its own, a project puts a `cserve.ini` into the working directory the command starts in, like the project root:

```ini
memory_limit = 512M
xdebug.mode = debug
```

Both commands read it after the system's settings and those of this package, so its values win, and announce it at startup. Other ini files in the directory are not read. PHP reads the settings once, so changes take effect when the command restarts.

## Live reload

By default, the command polls the watched files and tells open pages to update when they change:

- Changed stylesheets are swapped in place, including the stylesheets they import.
- Other changes, such as code or templates, morph the page into a freshly rendered copy with [Idiomorph](https://github.com/bigskysoftware/idiomorph), which the command serves itself. The scroll position, focus, and form input the user changed are kept, and scripts do not run again. The page reloads instead when its scripts changed, inline ones included, when a form posted to it without a redirect, or when its URL no longer answers with an HTML page, for example after a redirect.
- Changed scripts reload the page.

Pages that a morph would break, like pages whose scripts render markup or keep state in the DOM, opt out with a meta tag; they reload for every change other than stylesheets:

```html
<meta
	name="celema-live-reload"
	content="reload" />
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

## Live reload for other servers

The `reload` command watches files and serves live reload without serving the application, for applications that run in a container or under another local server, like Docker, DDEV, Herd, or Valet. Register it like the server commands:

```php
new Reload(watch: $watch, admin: 'http://localhost:2019'),
```

It prints the `CELEMA_LIVE_RELOAD` value the application needs to include the script with the [layout snippet](#live-reload), for example in a `compose.yaml`:

```yaml
environment:
    CELEMA_LIVE_RELOAD: http://localhost:21300/celema-live-reload.js
```

The endpoint listens on a fixed port, by default 21300, the server commands' default live reload port. A busy port is an error rather than a reason to pick another one, which the application would not know about. Pages connect from the browser, so the address must be reachable from there, not from the container.

With `--admin=<url>`, or the `admin` argument, changes to watched files other than stylesheets and scripts restart the workers of a FrankenPHP served elsewhere through its admin API, before pages update. In a container, the admin API only listens on the container's own loopback interface by default. Set `admin 0.0.0.0:2019` in its Caddyfile, and publish the port only on the host's loopback interface, like `127.0.0.1:2019:2019`: the admin API has no authentication.

The application does not get this package's [PHP settings](#php-settings) there; set `opcache.validate_timestamps=1` and `opcache.revalidate_freq=0` yourself. Changes must also reach the application's file system before pages update; with slowly synchronized volumes, a page may update before the change arrives.

The command takes `--host`, `--port`, `--admin`, `--quiet`, `--watch-files`, and `--no-companions`, and runs [companion processes](#companion-processes) like the server commands.

## Companion processes

Companion processes run alongside the server, like a CSS or JavaScript watcher or a queue worker. Name each one, with a command line for the shell or a list of arguments:

```php
new Server($docroot, companions: [
	'css' => 'npx @tailwindcss/cli -i src/app.css -o public/app.css --watch',
	'js' => ['npx', 'esbuild', 'src/app.js', '--bundle', '--outfile=public/app.js', '--watch'],
]),
```

Their output appears after their name, without colors and with only the last state of lines that redraw themselves, like progress bars. When a companion exits by itself, the command reports its exit code and keeps serving. Companions stop together with the server, including the processes they start, like the one `npx` runs. Their input stays open while the server runs, as watchers like esbuild's stop when it closes. Pass `--no-companions` to start the server without them, for example when the watchers already run elsewhere.

Each companion and the server run in their own process group, given the `pcntl` and `posix` extensions, so stopping the command stops every process they started, like the processes of `server --processes`.

## Installing FrankenPHP

Without a configured `executable`, the `frankenphp` command runs, in this order:

1. The pinned `version` from the shared cache.
2. `frankenphp` on `PATH`.
3. The newest FrankenPHP version in the shared cache.

When none is found, or the pinned version is not installed, the command offers to download it into the cache, for the latest release or the pinned version. It only asks in a terminal, and downloads nothing without your consent. A pinned version always runs as pinned; another FrankenPHP never stands in for it. Pin a version for every developer of a project to run the same FrankenPHP:

```php
new FrankenPhp($docroot, version: '1.12.7'),
```

`frankenphp:install` downloads the latest release, or the given version, without starting the server: `php run frankenphp:install 1.12.7`. It reports when a `frankenphp` on `PATH` still takes precedence over the installed version.

The builds come from the [FrankenPHP releases](https://github.com/php/frankenphp/releases) for macOS and Linux, on x86-64 and ARM. Each download is checked against its published checksum and run once before it is installed. A build takes about 180 MB, so all projects share one cache with a directory per version:

- macOS: `~/Library/Caches/celema/frankenphp`
- Linux: `$XDG_CACHE_HOME/celema/frankenphp`, by default `~/.cache/celema/frankenphp`

`CELEMA_FRANKENPHP_DIR` sets another directory. To remove a version, delete its directory. The lookups use GitHub's API, which allows 60 an hour without a token; set `GITHUB_TOKEN` or `GH_TOKEN` to raise the limit.

At startup, the command prints the FrankenPHP and PHP versions it runs. It warns about extensions the project's `composer.json` requires that the embedded PHP lacks: the downloaded builds include a broad, fixed set of extensions and cannot load others, Xdebug included. Use the `server` command for step debugging.

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
