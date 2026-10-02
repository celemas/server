# Changelog

## [Unreleased](https://codefloe.com/celema/server/compare/0.1.0...HEAD)

### Added

- `frankenphp --worker` serves the application with one FrankenPHP worker and implies `--watch`. Changes to watched files other than stylesheets and scripts restart the worker through FrankenPHP's admin API, bound to a random loopback port, before pages reload.

### Changed

- The default watch patterns include SQL and TPQL files: `**/*.{php,js,css,sql,tpql}`.

### Fixed

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
