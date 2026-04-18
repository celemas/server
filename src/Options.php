<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use Duon\Cli\Opts;

/** @internal */
final class Options
{
	public string $host = 'localhost';
	public int $port = 1983;
	public string $filter = '';
	public bool $debugger = false;
	public bool $quiet = false;
	public bool $watch = false;
	/** @var list<string> */
	public array $watchFiles = Setup::DEFAULT_WATCH;

	public static function from(int $defaultPort, array|string $defaultWatch): self
	{
		$opts = new Opts();
		$options = new self();
		$options->host = $opts->get('-h', $opts->get('--host', 'localhost'));
		$options->port = Setup::port($opts->get('-p', $opts->get(
			'--port',
			(string) $defaultPort,
		)));
		$options->filter = $opts->get('-f', $opts->get('--filter', ''));
		$options->debugger = $opts->has('-d', $opts->has('--debug'));
		$options->quiet = $opts->has('-q', $opts->has('--quiet'));
		$options->watch = $opts->has('-w', $opts->has('--watch'));
		$options->watchFiles = self::watchFiles($opts, $defaultWatch);

		return $options;
	}

	/** @return list<string> */
	private static function watchFiles(Opts $opts, array|string $defaultWatch): array
	{
		$watch = WatchPattern::list($defaultWatch);
		$values = self::watchValues($opts);

		if ($values === []) {
			return $watch;
		}

		return WatchPattern::list($values);
	}

	/** @return list<string> */
	private static function watchValues(Opts $opts): array
	{
		$values = [];

		if ($opts->has('-w')) {
			$values = array_merge($values, $opts->all('-w', []));
		}

		if ($opts->has('--watch')) {
			$values = array_merge($values, $opts->all('--watch', []));
		}

		return $values;
	}
}
