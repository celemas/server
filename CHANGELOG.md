# Changelog

## [Unreleased]

### Changed

- `--watch` no longer runs BrowserSync. The command now polls the watched files itself and serves a live reload script, so Node.js and `npx` are no longer required. The application listens on the requested port directly instead of behind a proxy. Pages must include the script from the `CELEMA_LIVE_RELOAD` environment variable to reload; see the README.

### Added

- Extracted the development server from `celema/core` into the standalone `celema/server` package. The classes moved from the `Celema\Core\Server` namespace to `Celema\Server`; the commands, options, watch mode, and the request-log protocol are unchanged.
