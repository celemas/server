<?php

declare(strict_types=1);

namespace Celema\Server;

/**
 * Work a reload waits for, such as a worker restart. The relay advances it
 * between reading the backend's output, so it never blocks that output.
 *
 * @internal
 */
interface Pending
{
	/**
	 * Streams to wait on besides the relay's own, readable when the work
	 * may advance.
	 *
	 * @return list<resource>
	 */
	public function streams(): array;

	/** Advances the work without blocking; returns whether it is finished. */
	public function advance(): bool;
}
