<?php

declare(strict_types=1);

namespace Duon\Core\Server;

use Duon\Cli\Opts;

/** @internal */
final class ServerOptions
{
	public string $host = 'localhost';
	public int $port = 1983;
	public string $filter = '';
	public bool $debugger = false;
	public bool $quiet = false;
	public bool $watch = false;

	public static function from(int $defaultPort): self
	{
		$opts = new Opts();
		$options = new self();
		$options->host = $opts->get('-h', $opts->get('--host', 'localhost'));
		$options->port = ServerSupport::port($opts->get('-p', $opts->get(
			'--port',
			(string) $defaultPort,
		)));
		$options->filter = $opts->get('-f', $opts->get('--filter', ''));
		$options->debugger = $opts->has('-d', $opts->has('--debug'));
		$options->quiet = $opts->has('-q', $opts->has('--quiet'));
		$options->watch = $opts->has('-w', $opts->has('--watch'));

		return $options;
	}
}
