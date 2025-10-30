<?php

// phpcs:ignoreFile

declare(strict_types=1);

require_once 'functions.php';

if (PHP_SAPI !== 'cli') {
	$uri = $_SERVER['REQUEST_URI'] ?? '';
	$routePrefix = getenv('DUON_ROUTE_PREFIX');
	$publicDir = getenv('DUON_DOCUMENT_ROOT');

	if ($routePrefix !== false) {
		$uri = preg_replace('/^' . preg_quote($routePrefix, '/') . '/', '', $uri);
	}

	$url = urldecode(parse_url($uri, PHP_URL_PATH));

	$start = microtime(true);

	if ($publicDir) {
		// serve existing files as-is
		if (is_file($publicDir . $url)) {
			/** @psalm-suppress PossiblyInvalidArgument */
			serverEcho(http_response_code() ?: 0, $uri, microtime(true) - $start);

			return false;
		}

		if (is_file($publicDir . rtrim($url, '/') . '/index.html')) {
			/** @psalm-suppress PossiblyInvalidArgument */
			serverEcho(http_response_code() ?: 0, $uri, microtime(true) - $start);

			return false;
		}

		if ($url === '/phpinfo') {
			echo phpinfo();
			/** @psalm-suppress PossiblyInvalidArgument */
			serverEcho(http_response_code() ?: 0, $uri, microtime(true) - $start);

			return true;
		}

		$_SERVER['SCRIPT_NAME'] = 'index.php';

		/** @psalm-suppress UnresolvableInclude, MixedAssignment */
		$response = require_once $publicDir . '/index.php';

		if ($response) {
			/** @psalm-suppress MixedMethodCall, MixedArgument */
			serverEcho($response->getStatusCode(), $uri, microtime(true) - $start);
		}

		return true;
	}

	return false;
}
