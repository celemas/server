<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * A FrankenPHP release's build for this platform.
 *
 * @internal
 */
final readonly class FrankenRelease
{
	/** @param ?string $sha256 The published checksum; old releases have none */
	public function __construct(
		public string $version,
		public string $asset,
		public string $url,
		public int $size,
		public ?string $sha256,
	) {}

	/** The size in megabytes, for messages. */
	public function megabytes(): string
	{
		return sprintf('%.1f MB', $this->size / 1_000_000);
	}
}
