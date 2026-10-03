<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Downloads a FrankenPHP build. Unlike release lookups, downloads are
 * sent without a token: the hosts that serve the builds reject it.
 *
 * @internal
 */
final class FrankenDownload
{
	/**
	 * Downloads the release's build into the file. The progress callback
	 * receives the number of bytes written so far and returns false to
	 * cancel the download.
	 *
	 * @param callable(int): bool $progress
	 * @return ?string An error message, or null once downloaded
	 */
	public static function run(FrankenRelease $release, string $file, callable $progress): ?string
	{
		$context = stream_context_create([
			'http' => [
				'header' => [Http::AGENT],
				'ignore_errors' => true,
				'timeout' => 30,
			],
		]);
		/** @var resource|false $in */
		$in = ErrorTrap::run(static fn(): mixed => fopen($release->url, 'rb', false, $context), $error);

		if ($in === false) {
			return "Could not download {$release->asset}: " . ($error ?? 'no answer');
		}

		/** @var mixed $headers */
		$headers = stream_get_meta_data($in)['wrapper_data'] ?? [];
		$status = Http::status(is_array($headers) ? $headers : []);

		if ($status !== 200) {
			fclose($in);

			return "Could not download {$release->asset}: the server answered {$status}.";
		}

		$out = ErrorTrap::run(static fn(): mixed => fopen($file, 'wb'), $error);

		if (!is_resource($out)) {
			fclose($in);

			return "Could not write {$file}: " . ($error ?? 'unknown error');
		}

		try {
			return self::copy($in, $out, $release, $progress);
		} finally {
			fclose($in);
			fclose($out);
		}
	}

	/**
	 * @param resource $in
	 * @param resource $out
	 * @param callable(int): bool $progress
	 */
	private static function copy(mixed $in, mixed $out, FrankenRelease $release, callable $progress): ?string
	{
		$bytes = 0;

		while (!feof($in)) {
			$chunk = fread($in, 65_536);

			if ($chunk === false) {
				return "The download of {$release->asset} failed.";
			}

			if (fwrite($out, $chunk) !== strlen($chunk)) {
				return "Could not save the download of {$release->asset}; is the disk full?";
			}

			$bytes += strlen($chunk);

			if (!$progress($bytes)) {
				return 'The download was canceled.';
			}
		}

		return $bytes === $release->size ? null : "The download of {$release->asset} is incomplete.";
	}
}
