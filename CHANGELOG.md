# Changelog

## [Unreleased](https://codefloe.com/celema/server/compare/0.2.0...HEAD)

### Added

- `frankenphp --worker=<count>` runs the requested number of workers for concurrent PHP requests. The count must be a positive integer; bare `--worker` still starts one worker and implies `--watch`.

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
