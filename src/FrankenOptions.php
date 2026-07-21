<?php

declare(strict_types=1);

namespace Celema\Core\Server;

use Celema\Console\Args;

/** @internal */
final class FrankenOptions
{
	public string $host = 'localhost';
	public int $port = 1983;
	public string $filter = '';
	public bool $debug = false;
	public bool $quiet = false;
	public bool $watch = false;
	/** @var list<string> */
	public array $watchFiles = Setup::DEFAULT_WATCH;

	public static function from(int $defaultPort, array|string $defaultWatch, Args $args): self
	{
		$options = new self();
		$options->host = $args->opt('-h', $args->opt('--host', 'localhost'));
		$options->port = Setup::port($args->opt('-p', $args->opt('--port', (string) $defaultPort)));
		$options->filter = Options::filter($args->opt('-f', $args->opt('--filter', '')));
		$options->debug = $args->has('-d') || $args->has('--debug');
		$options->quiet = $args->has('-q') || $args->has('--quiet');
		$options->watch = $args->has('-w') || $args->has('--watch');
		$options->watchFiles = Options::watchFiles($args, $defaultWatch);

		return $options;
	}
}
