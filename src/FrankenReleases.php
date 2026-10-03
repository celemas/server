<?php

declare(strict_types=1);

namespace Celema\Server;

use SensitiveParameter;

/**
 * Looks up FrankenPHP releases and their build for this platform through
 * GitHub's API.
 *
 * `CELEMA_FRANKENPHP_RELEASES` overrides the API address. A token from
 * `GITHUB_TOKEN` or `GH_TOKEN` raises the rate limit of 60 lookups an
 * hour; it is only sent to the API, as the hosts that serve the builds
 * reject it.
 *
 * @internal
 */
final readonly class FrankenReleases
{
	private const string API = 'https://api.github.com/repos/php/frankenphp/releases';

	public function __construct(
		private string $api,
		private string $asset,
		#[SensitiveParameter]
		private ?string $token = null,
	) {}

	/** The releases for this platform, or an error message. */
	public static function create(): self|string
	{
		$asset = self::asset(PHP_OS_FAMILY, php_uname('m'));

		if ($asset === null) {
			return 'No FrankenPHP build is available for download on this platform; see https://frankenphp.dev/docs/.';
		}

		$api = self::env('CELEMA_FRANKENPHP_RELEASES');

		return new self(
			$api === null ? self::API : rtrim($api, '/'),
			$asset,
			self::env('GITHUB_TOKEN') ?? self::env('GH_TOKEN'),
		);
	}

	/**
	 * The name of the release asset for the platform. Windows builds come
	 * as archives, which are not installed automatically.
	 */
	public static function asset(string $os, string $machine): ?string
	{
		return match (true) {
			$os === 'Darwin' && in_array($machine, ['arm64', 'aarch64'], true) => 'frankenphp-mac-arm64',
			$os === 'Darwin' && $machine === 'x86_64' => 'frankenphp-mac-x86_64',
			$os === 'Linux' && in_array($machine, ['x86_64', 'amd64'], true) => 'frankenphp-linux-x86_64',
			$os === 'Linux' && in_array($machine, ['aarch64', 'arm64'], true) => 'frankenphp-linux-aarch64',
			default => null,
		};
	}

	public function latest(): FrankenRelease|string
	{
		return $this->fetch("{$this->api}/latest", 'the latest FrankenPHP release');
	}

	public function tag(string $version): FrankenRelease|string
	{
		return $this->fetch("{$this->api}/tags/v{$version}", "FrankenPHP {$version}");
	}

	private function fetch(string $url, string $what): FrankenRelease|string
	{
		if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
			return 'Downloading FrankenPHP needs the allow_url_fopen setting.';
		}

		if (str_starts_with($url, 'https:') && !in_array('https', stream_get_wrappers(), true)) {
			return 'Downloading FrankenPHP needs the openssl extension.';
		}

		$headers = ['Accept: application/vnd.github+json', Http::AGENT];

		if ($this->token !== null) {
			$headers[] = "Authorization: Bearer {$this->token}";
		}

		$context = stream_context_create(['http' => ['header' => $headers, 'ignore_errors' => true, 'timeout' => 15]]);
		/** @var string|false $body */
		$body = ErrorTrap::run(static fn(): mixed => file_get_contents($url, false, $context), $error);

		if ($body === false) {
			return "Could not look up {$what} at {$url}: " . ($error ?? 'no answer');
		}

		/** @var list<string> $headers */
		$headers = http_get_last_response_headers() ?? [];
		$status = Http::status($headers);

		if ($status === 404) {
			return ucfirst($what) . ' was not found.';
		}

		if (in_array($status, [403, 429], true) && Http::header($headers, 'x-ratelimit-remaining') === '0') {
			$reset = Http::header($headers, 'x-ratelimit-reset') ?? '';
			$until = ctype_digit($reset) ? ' until ' . date('H:i', (int) $reset) : '';

			return "GitHub's API rate limit is exhausted{$until}. Set GITHUB_TOKEN to raise it.";
		}

		if ($status !== 200) {
			return "Could not look up {$what}: GitHub answered {$status}.";
		}

		return $this->release($body, $what);
	}

	private function release(string $body, string $what): FrankenRelease|string
	{
		/** @var mixed $data */
		$data = json_decode($body, true);
		/** @var mixed $tag */
		$tag = is_array($data) ? $data['tag_name'] ?? null : null;
		$version = is_string($tag) ? FrankenCache::version($tag) : null;
		/** @var mixed $assets */
		$assets = is_array($data) ? $data['assets'] ?? [] : [];

		if ($version === null || !is_array($assets)) {
			return "Could not read the release information of {$what}.";
		}

		/** @var mixed $asset */
		foreach ($assets as $asset) {
			if (!is_array($asset) || ($asset['name'] ?? null) !== $this->asset) {
				continue;
			}

			/** @var mixed $url */
			$url = $asset['browser_download_url'] ?? null;
			/** @var mixed $size */
			$size = $asset['size'] ?? null;
			/** @var mixed $digest */
			$digest = $asset['digest'] ?? null;

			if (!is_string($url) || !is_int($size)) {
				break;
			}

			$sha256 = is_string($digest) && preg_match('/^sha256:([0-9a-f]{64})$/D', $digest, $match) === 1
				? $match[1]
				: null;

			return new FrankenRelease($version, $this->asset, $url, $size, $sha256);
		}

		return "FrankenPHP {$version} has no {$this->asset} build.";
	}

	private static function env(string $name): ?string
	{
		$value = getenv($name);

		return is_string($value) && $value !== '' ? $value : null;
	}
}
