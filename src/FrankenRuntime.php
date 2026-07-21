<?php

declare(strict_types=1);

namespace Celema\Core\Server;

/** @internal */
final class FrankenRuntime extends Runtime
{
	private ?string $config = null;

	protected function start(int $port): Process|string
	{
		$contents = $this->setup->frankenPhpCaddyfile(
			$this->options->host,
			$port,
			$this->options->debug,
		);

		if ($contents !== null) {
			$this->config = self::write($contents);

			if ($this->config === null) {
				return 'Failed to create the FrankenPHP configuration.';
			}
		}

		$frankenPhp = Process::start(
			$this->setup->frankenPhpCommand(
				$this->options->host,
				$port,
				$this->options->debug,
				$this->config,
			),
			$this->setup->frankenPhpEnvironment(),
		);

		return $frankenPhp ?? 'Failed to start FrankenPHP.';
	}

	protected function label(): string
	{
		return 'FrankenPHP';
	}

	protected function missing(): ?string
	{
		return $this->setup->missingFrankenPhp() ? 'FrankenPHP requires frankenphp in PATH.' : null;
	}

	protected function cleanup(): void
	{
		if ($this->config !== null && is_file($this->config)) {
			unlink($this->config);
		}

		$this->config = null;
	}

	private static function write(string $contents): ?string
	{
		$file = tempnam(sys_get_temp_dir(), 'celema-frankenphp-');

		if ($file === false) {
			return null;
		}

		if (file_put_contents($file, $contents) === false) {
			unlink($file);

			return null;
		}

		return $file;
	}
}
