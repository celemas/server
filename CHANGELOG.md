# Changelog

## [Unreleased]

Initial release. The development server was previously part of `celema/core` in the `Celema\Core\Server` namespace.

- `Server` command that serves the application with the PHP CLI's built-in server, with an optional Xdebug session.
- `FrankenPhp` command that serves the application with the `frankenphp` executable in classic mode, with optional verbose Caddy logs.
- Host, port, and route prefix configuration, with a port availability check at startup.
- `--open` option that opens the application in the default browser once it responds.
- Colored request log lines with status, method, duration, and XHR and exception markers, filterable by regex.
- Request log protocol through which the served application reports handled requests and exceptions, inert outside the dev server.
- `--watch` mode with live reload: watched files are polled without Node.js or other external tools, patterns starting with `!` exclude files, pages that include the script from the `CELEMA_LIVE_RELOAD` environment variable reload on changes, and changed stylesheets are swapped in place.
