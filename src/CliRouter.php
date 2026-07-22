<?php

// phpcs:ignoreFile

declare(strict_types=1);

require_once __DIR__ . '/Console.php';
require_once __DIR__ . '/functions.php';

if (PHP_SAPI !== 'cli') {
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	$routePrefix = getenv('CELEMA_ROUTE_PREFIX');
	$publicDir = getenv('CELEMA_DOCUMENT_ROOT');

	if ($routePrefix !== false) {
		$uri = preg_replace('/^' . preg_quote($routePrefix, '/') . '/', '', $uri) ?? $uri;
	}

	// parse_url() returns null/false for pathless or seriously malformed URIs.
	$path = parse_url($uri, PHP_URL_PATH);
	$url = urldecode(is_string($path) ? $path : '/');

	$start = microtime(true);

	if ($publicDir !== false && $publicDir !== '') {
		\Celema\Server\Console::clearException();

		// serve existing files as-is
		if (is_file($publicDir . $url)) {
			$status = http_response_code();
			serverEcho(is_int($status) ? $status : 0, $uri, microtime(true) - $start);

			return false;
		}

		if (is_file($publicDir . rtrim($url, '/') . '/index.html')) {
			$status = http_response_code();
			serverEcho(is_int($status) ? $status : 0, $uri, microtime(true) - $start);

			return false;
		}

		if ($url === '/phpinfo') {
			// @mago-expect lint:no-debug-symbols
			echo phpinfo();
			$status = http_response_code();
			serverEcho(is_int($status) ? $status : 0, $uri, microtime(true) - $start);

			return true;
		}

		$_SERVER['SCRIPT_NAME'] = 'index.php';

		/** @psalm-suppress UnresolvableInclude, MixedAssignment */
		$response = require_once $publicDir . '/index.php';

		if ($response) {
			$fromHandler = \Celema\Server\Console::hasException();

			/** @psalm-suppress MixedMethodCall, MixedArgument */
			serverEcho($response->getStatusCode(), $uri, microtime(true) - $start, $fromHandler);
		}

		\Celema\Server\Console::flushException();

		return true;
	}

	return false;
}
