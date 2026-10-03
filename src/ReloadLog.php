<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Io;

/**
 * The live reload messages: what is watched, and how pages were updated
 * after a change.
 *
 * @internal
 */
final class ReloadLog
{
	/** Changes this soon after the start, like a companion's first build, need no hint. */
	private const int SETTLING = 2_000_000_000;

	private readonly int $started;

	/** @param list<string> $patterns */
	public function __construct(
		private readonly Io $io,
		private readonly bool $quiet,
		private readonly array $patterns,
	) {
		$this->started = (int) hrtime(true);
	}

	public function announce(LiveReload $liveReload): void
	{
		$this->io->echoln("Live reload script: {$liveReload->script}");
		$watched = $liveReload->watched();

		if ($watched > 0) {
			$this->io->echoln('<dim>Watching ' . ($watched === 1 ? '1 file' : "{$watched} files") . '</dim>');

			return;
		}

		// A typo in a pattern would otherwise go unnoticed.
		$this->io->echoln(
			'<yellow>No files match the watch patterns:</yellow> ' . $this->io->escape(implode(', ', $this->patterns)),
		);
	}

	/** @param list<string> $files */
	public function changed(string $event, array $files, int $clients): void
	{
		if ($this->quiet && $clients > 0) {
			return;
		}

		$more = count($files) > 1 ? ' <dim>(+' . (count($files) - 1) . ' more)</dim>' : '';
		$file = $this->io->escape($files[0] ?? '') . $more;
		$timestamp = '<dim>' . RequestOutput::timestamp() . '</dim>';

		if ($clients === 0) {
			if ((hrtime(true) - $this->started) < self::SETTLING) {
				return;
			}

			$this->io->echoln(
				"{$timestamp} <yellow>changed</yellow> {$file} "
					. '<dim>· no page connected, include the live reload script</dim>',
			);

			return;
		}

		$action = $event === 'css' ? 'restyle' : $event;
		$pages = $clients === 1 ? '1 page' : "{$clients} pages";
		$this->io->echoln("{$timestamp} <magenta>{$action}</magenta> {$file} <dim>· {$pages}</dim>");
	}
}
