<?php

declare(strict_types=1);

namespace Celema\Server;

use Override;

/** @internal */
final class FrankenRuntime extends Runtime
{
	private ?string $config = null;
	private ?int $adminPort = null;

	#[Override]
	protected function start(int $port, ?string $liveReload): Process|string
	{
		$error = $this->loadIni();

		if ($error !== null) {
			return $error;
		}

		if ($this->options->workers !== null && $this->options->watch) {
			$adminPort = Ports::ephemeral();

			if (is_string($adminPort)) {
				return $adminPort;
			}

			$this->adminPort = $adminPort;
		}

		$this->config = self::write($this->setup->frankenPhpCaddyfile(
			$this->options->host,
			$port,
			$this->options->debug,
			$this->adminPort,
			$this->options->workers,
		));

		if ($this->config === null) {
			return 'Failed to create the FrankenPHP configuration.';
		}

		$frankenPhp = Process::start(
			$this->setup->frankenPhpCommand($this->config),
			$this->setup->frankenPhpEnvironment($liveReload, $this->ini?->dir),
			group: true,
		);

		return $frankenPhp ?? 'Failed to start FrankenPHP.';
	}

	#[Override]
	protected function reloading(string $event, array $files): ?Pending
	{
		if ($this->adminPort === null || !WorkerRestart::needed($files)) {
			return null;
		}

		return WorkerRestart::send('127.0.0.1', $this->adminPort, $this->log->restarted(...));
	}

	#[Override]
	protected function details(): string
	{
		$version = Capture::output($this->setup->frankenPhpVersionCommand()) ?? '';

		return preg_match('/^FrankenPHP v?(\S+) PHP (\S+)/', $version, $match) === 1
			? "FrankenPHP {$match[1]}, PHP {$match[2]}"
			: '';
	}

	#[Override]
	protected function started(): void
	{
		$missing = FrankenProbe::missing($this->setup, (string) getcwd());

		if ($missing !== []) {
			$this->io->warn('FrankenPHP lacks extensions the project requires: ' . implode(', ', $missing) . '.');
		}
	}

	#[Override]
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
