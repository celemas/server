<?php

declare(strict_types=1);

namespace Celema\Core\Tests;

use Celema\Console\BufferedIo;
use Celema\Core\Server\FrankenOutput;

final class FrankenOutputTest extends TestCase
{
	public function testAccessLogRendersRequest(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: false);
		$output->line($this->access('/foo'));

		$this->assertMatchesRegularExpression(
			'#^\d{2}:\d{2}:\d{2}\.\d{2} 200 GET /foo \.+ 0\.01235s\n$#',
			$io->output(),
		);
	}

	public function testAccessLogUsesExceptionMarkerAndXhrHeader(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: false);
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'celema-exception {"method":"POST","uri":"/api","lines":["RuntimeException: Boom","in /app.php:10"]}',
		]));
		$output->line($this->access('/api', method: 'POST', headers: [
			'x-requested-with' => ['XMLHttpRequest'],
		]));

		$lines = explode("\n", trim($io->output()));
		$this->assertCount(3, $lines);
		$this->assertStringContainsString('[EXC][XHR] 0.01235s', $lines[0]);
		$this->assertSame('RuntimeException: Boom', $lines[1]);
		$this->assertSame('in /app.php:10', $lines[2]);
	}

	public function testPendingExceptionMarkersAreBounded(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: false);

		for ($i = 0; $i <= 100; $i++) {
			$output->line($this->entry([
				'logger' => 'frankenphp',
				'msg' => "celema-exception {\"method\":\"GET\",\"uri\":\"/page-{$i}\"}",
			]));
		}

		$output->line($this->access('/page-0'));
		$output->line($this->access('/page-100'));

		$lines = explode("\n", trim($io->output()));
		$this->assertCount(2, $lines);
		$this->assertStringNotContainsString('[EXC]', $lines[0]);
		$this->assertStringContainsString('[EXC]', $lines[1]);
	}

	public function testAccessLogFilterAndStringXhrHeader(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '#health#', 60, quiet: false, debug: false);
		$output->line($this->access('/health'));
		$output->line($this->access('/home', headers: ['X-Requested-With' => 'xmlhttprequest']));

		$this->assertStringNotContainsString('/health', $io->output());
		$this->assertStringContainsString('[XHR]', $io->output());
	}

	public function testStartupOutputHonorsQuietMode(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: true, debug: false);
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'FrankenPHP started 🐘',
		]));
		$output->line($this->entry(['msg' => 'Caddy serving PHP app on localhost:1983']));
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'PHP warning',
		]));

		$this->assertSame("PHP warning\n", $io->output());
	}

	public function testStartupOutputShowsServerMessages(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: false);
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'FrankenPHP started 🐘',
		]));
		$output->line($this->entry(['msg' => 'Caddy serving PHP app on localhost:1983']));

		$this->assertSame(
			"FrankenPHP started 🐘\nCaddy serving PHP app on localhost:1983\n",
			$io->output(),
		);
	}

	public function testDebugOutputPassesOtherJsonThrough(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: true);
		$line = $this->entry(['level' => 'debug', 'msg' => 'config']);
		$output->line($line);

		$this->assertSame($line . "\n", $io->output());
	}

	public function testErrorsGoToStderr(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: false);
		$output->line($this->entry([
			'level' => 'error',
			'msg' => 'startup failed',
			'error' => 'address in use',
		]));

		$this->assertSame('', $io->output());
		$this->assertStringContainsString('startup failed: address in use', $io->errorOutput());
	}

	public function testMalformedOutputPassesThrough(): void
	{
		$io = new BufferedIo();
		$output = new FrankenOutput($io, '', 60, quiet: false, debug: false);
		$output->line("plain <output>\n");
		$output->line($this->entry([
			'logger' => 'http.log.access',
			'msg' => 'handled request',
		]));

		$this->assertStringContainsString("plain <output>\n", $io->output());
		$this->assertStringContainsString('http.log.access', $io->output());
	}

	/** @param array<string, mixed> $headers */
	private function access(string $uri, string $method = 'GET', array $headers = []): string
	{
		return $this->entry([
			'level' => 'info',
			'ts' => 1_784_570_344.75,
			'logger' => 'http.log.access.log0',
			'msg' => 'handled request',
			'request' => [
				'method' => $method,
				'uri' => $uri,
				'headers' => $headers,
			],
			'duration' => 0.012_345,
			'status' => 200,
		]);
	}

	/** @param array<string, mixed> $entry */
	private function entry(array $entry): string
	{
		$json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$this->assertIsString($json);

		return $json;
	}
}
