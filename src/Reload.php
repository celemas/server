<?php

declare(strict_types=1);

namespace Celema\Server;

use Celema\Console\Args;
use Celema\Console\Command;
use Celema\Console\Io;
use Celema\Console\Opt;
use InvalidArgumentException;

/** @api */
#[Command('reload', 'Watch files and serve live reload for an application served elsewhere')]
#[Opt(
	'--host',
	'Host to bind the live reload endpoint to. Defaults to localhost.',
	short: '-h',
	value: 'host',
)]
#[Opt('--port', 'Port of the live reload endpoint.', short: '-p', value: 'port')]
#[Opt(
	'--admin',
	"Address of FrankenPHP's admin API, like http://localhost:2019; watched changes to files other than CSS or JS restart its workers.",
	value: 'url',
)]
#[Opt('--quiet', 'Reduce live reload output.', short: '-q')]
#[Opt('--no-companions', 'Do not start the configured companion processes.')]
#[Opt(
	'--watch-files',
	'Override the configured watch patterns. Repeat the option or separate patterns with commas.',
	value: 'glob',
)]
class Reload
{
	/**
	 * The default port matches the one the server command uses for live
	 * reload by default, so pages keep loading the script when switching.
	 */
	public function __construct(
		protected readonly int $port = 21_300,
		protected readonly array|string $watch = Setup::DEFAULT_WATCH,
		protected readonly ?string $admin = null,
		protected readonly array $companions = [],
	) {}

	public function __invoke(Args $args, Io $io): int
	{
		try {
			$options = Options::from($this->port, $this->watch, $args);
			$admin = $args->opt('--admin', $this->admin ?? '');
			$runtime = new ReloadRuntime(
				$options,
				$io,
				$admin === '' ? null : self::admin($admin),
				Companion::validate($this->companions),
			);
			// Without a backend, only companions have output, which they show themselves.
			$result = $runtime->run(static function (string $_): void {});

			if (is_string($result)) {
				$io->error($result);

				return 1;
			}

			return $result;
		} catch (InvalidArgumentException $e) {
			$io->error($e->getMessage());

			return 1;
		}
	}

	/**
	 * The host and port of an admin API address; the port defaults to
	 * 2019, the one of Caddy and FrankenPHP.
	 *
	 * @return array{host: string, port: int}
	 */
	private static function admin(string $url): array
	{
		$parts = parse_url(str_contains($url, '://') ? $url : "http://{$url}");
		$host = is_array($parts) ? $parts['host'] ?? '' : '';

		if ($host === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'http') {
			throw new InvalidArgumentException(
				"Invalid admin API address '{$url}'; use an http address like http://localhost:2019.",
			);
		}

		return ['host' => $host, 'port' => $parts['port'] ?? 2019];
	}
}
