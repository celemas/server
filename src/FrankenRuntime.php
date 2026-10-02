<?php

declare(strict_types=1);

namespace Celema\Server;

use Override;

/** @internal */
final class FrankenRuntime extends Runtime
{
	private ?string $config = null;
	private ?WorkerRestart $restart = null;

	#[Override]
	protected function start(int $port, ?string $liveReload): Process|string
	{
		if ($this->options->worker) {
			$adminPort = Ports::ephemeral();

			if (is_string($adminPort)) {
				return $adminPort;
			}

			$this->restart = new WorkerRestart($adminPort);
		}

		$contents = $this->setup->frankenPhpCaddyfile(
			$this->options->host,
			$port,
			$this->options->debug,
			$this->restart?->adminPort,
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
			$this->setup->frankenPhpEnvironment($liveReload),
		);

		return $frankenPhp ?? 'Failed to start FrankenPHP.';
	}

	#[Override]
	protected function reloading(string $event, array $files): void
	{
		if ($this->restart === null || !WorkerRestart::needed($files)) {
			return;
		}

		$timestamp = '<dim>' . RequestOutput::timestamp() . '</dim>';
		$error = ($this->restart)();

		if ($error !== null) {
			$this->io->echoln("{$timestamp} <red>" . $this->io->escape($error) . '</red>');

			return;
		}

		if (!$this->options->quiet) {
			$this->io->echoln("{$timestamp} <cyan>restart</cyan> worker");
		}
	}

	#[Override]
	protected function missing(): ?string
	{
		return $this->setup->missingFrankenPhp() ? 'FrankenPHP requires frankenphp in PATH.' : null;
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
