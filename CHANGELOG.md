# Changelog

## [Unreleased](https://codefloe.com/celema/server/compare/0.2.0...HEAD)

### Added

- `frankenphp --worker=<count>` runs the requested number of workers for concurrent PHP requests. The count must be a positive integer; bare `--worker` still starts one worker.
- `frankenphp` downloads FrankenPHP into a cache that all projects share when it finds none, once the user agrees in a terminal. Builds are checked against their published checksum and run once before they are installed. The new `frankenphp:install [<version>]` command, `Celema\Server\FrankenInstall`, downloads a release without starting the server.
- The new `version` argument of `FrankenPhp` pins the FrankenPHP version from the shared cache. Without a pin, `frankenphp` on `PATH` comes before the newest cached version.
- `frankenphp` prints the FrankenPHP and PHP versions at startup, and warns about extensions the project's `composer.json` requires that FrankenPHP's embedded PHP lacks.
- Both commands run companion processes alongside the server, like asset watchers, configured with the new `companions` argument. Their output appears with their names; when one exits, the server keeps running, and they stop with the server, together with the processes they start. `--no-companions` skips them.
- The new `reload` command, `Celema\Server\Reload`, watches files and serves live reload without serving the application, for applications that run elsewhere, like in a container. Its endpoint listens on a fixed port, and with `--admin=<url>`, changes restart the workers of a FrankenPHP served elsewhere through its admin API.
- `vendor/bin/cserve` runs the commands for any PHP application without a run script of its own. It serves the first of `public/`, `web/`, or `htdocs/` with an `index.php`, and runs `server` without a command.
- Both commands read the project's own PHP settings from a `cserve.ini` in the working directory, after the system's settings and the package's.
- `server --processes=<count>` serves requests concurrently with the given number of PHP server processes through `PHP_CLI_SERVER_WORKERS`. The count must be a positive integer; `--processes=1` also overrides an inherited setting.

### Changed

- **Breaking:** The default application port for `server` and `frankenphp` is now `2130` instead of `1983`. The derived live reload port and the standalone `reload` command's default are now `21300` instead of `19830`. Explicitly configured ports are unchanged; update fixed URLs that relied on the old defaults, or set `--port` to retain them.
- **Breaking:** `server` and `frankenphp` now watch files and serve live reload by default, including in worker mode. Use `--no-watch` to disable file watching, live reload, and automatic worker restarts. Workers can run without watching; in that case the FrankenPHP admin API stays disabled.
- **Breaking:** Removed `--watch` and `-w`. Remove bare occurrences from command invocations, and replace `--watch=<glob>` or `-w=<glob>` with `--watch-files=<glob>`. Pattern overrides still support repeated options and comma-separated patterns, but do not re-enable watching with `--no-watch`.
- `frankenphp` runs FrankenPHP with a generated configuration in classic mode too, instead of its `php-server` command. Responses are compressed with Zstandard or gzip as before, but no longer with Brotli, which not every FrankenPHP build includes.
- The `executable` argument of `FrankenPhp` defaults to none, which looks up FrankenPHP as described above. A configured executable that does not exist is reported by its path.
- A busy application port is reported with a hint that another server may still be running on it, and to choose another port with `--port`.
- Both commands start with a line that names the served address, with the PHP version for `server`. The startup messages of the PHP server and FrankenPHP are hidden instead, and `frankenphp --quiet` only reduces live reload output.

### Fixed

- Ctrl+C, or a SIGTERM sent to the command, stops the backend and removes the temporary FrankenPHP configuration, given the `pcntl` extension. The configuration was left in the temporary directory, and a SIGTERM sent to the command alone left the backend running. The command exits with 130 or 143, as shells report a stopped process.
- Stopping the command stops every process of the server, like the forked processes of a PHP server with multiple processes, which kept the port taken. The server runs in its own process group, given the `pcntl` and `posix` extensions.
- The server process no longer inherits the live reload endpoint's listening socket, which kept the live reload port taken when the server outlived the command.
- The `server` request log renders the lines of a PHP server that runs multiple processes, for example through an inherited `PHP_CLI_SERVER_WORKERS`. The process ID prefix of these lines left request lines unrendered and connection lines visible.

## [0.2.0](https://codefloe.com/celema/server/src/tag/0.2.0) (2026-10-02)

### Added

- `frankenphp --worker` serves the application with one FrankenPHP worker and implies `--watch`. Changes to watched files other than stylesheets and scripts restart the worker through FrankenPHP's admin API, bound to a random loopback port, before pages update.
- Live reload morphs pages into a freshly rendered copy with Idiomorph when files other than stylesheets and scripts change, which keeps the scroll position, focus, and changed form input. Pages opt out with `<meta name="celema-live-reload" content="reload">`, and a `celema:morphed` event follows each morph. Changed scripts still reload the page.

### Changed

- Live reload morphs pages by default instead of reloading them when files other than stylesheets and scripts change. Pages whose scripts render markup or keep state in the DOM, which a morph would break, keep reloading with `<meta name="celema-live-reload" content="reload">`.
- The default watch patterns include SQL and TPQL files: `**/*.{php,js,css,sql,tpql}`.

### Fixed

- Both commands make OPcache check for changed files on every request. With the default `opcache.revalidate_freq` of two seconds, a request soon after an earlier one could run the previous version of a file that was just saved, which live reload made likely.
- Live reload applies changes to stylesheets that a linked stylesheet imports with `@import`. Only the linked file was fetched anew; the browser kept taking its imports from the cache, for good when they were served as immutable.
- `frankenphp` with a route prefix binds to `--host` and answers requests under every host name. The generated configuration used the host only to match the `Host` header, so the server listened on all interfaces, and requests under another name for the same address, such as `127.0.0.1` for the default `localhost`, got an empty response.

## [0.1.0](https://codefloe.com/celema/server/src/tag/0.1.0) (2026-09-27)

Initial release. The development server was previously part of `celema/core` in the `Celema\Core\Server` namespace.

- `Server` command that serves the application with the PHP CLI's built-in server, with an optional Xdebug session.
- `FrankenPhp` command that serves the application with the `frankenphp` executable in classic mode, with optional verbose Caddy logs.
- Host, port, and route prefix configuration, with a port availability check at startup.
- `--open` option that opens the application in the default browser once it responds.
- Colored request log lines with status, method, duration, and XHR and exception markers, filterable by regex.
- Request log protocol through which the served application reports handled requests and exceptions, inert outside the dev server.
- `--watch` mode with live reload: watched files are polled without Node.js or other external tools, patterns starting with `!` exclude files, pages that include the script from the `CELEMA_LIVE_RELOAD` environment variable reload on changes, and changed stylesheets are swapped in place.
