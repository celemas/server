<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Server\FrankenOutput;
use PHPUnit\Framework\TestCase;

final class FrankenOutputTest extends TestCase
{
	public function testAccessLogRendersRequest(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: false);
		$output->line($this->access('/foo'));

		$this->assertMatchesRegularExpression(
			'#^\d{2}:\d{2}:\d{2}\.\d{2} 200 GET /foo \.+ 0\.01235s\n$#',
			$buffer->output(),
		);
	}

	public function testAccessLogUsesExceptionMarkerAndXhrHeader(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: false);
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'celema-exception {"method":"POST","uri":"/api","lines":["RuntimeException: Boom","in /app.php:10"]}',
		]));
		$output->line($this->access('/api', method: 'POST', headers: [
			'x-requested-with' => ['XMLHttpRequest'],
		]));

		$lines = explode("\n", trim($buffer->output()));
		$this->assertCount(3, $lines);
		$this->assertStringContainsString('[EXC][XHR] 0.01235s', $lines[0]);
		$this->assertSame('RuntimeException: Boom', $lines[1]);
		$this->assertSame('in /app.php:10', $lines[2]);
	}

	public function testPendingExceptionMarkersAreBounded(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: false);

		for ($i = 0; $i <= 100; $i++) {
			$output->line($this->entry([
				'logger' => 'frankenphp',
				'msg' => "celema-exception {\"method\":\"GET\",\"uri\":\"/page-{$i}\"}",
			]));
		}

		$output->line($this->access('/page-0'));
		$output->line($this->access('/page-100'));

		$lines = explode("\n", trim($buffer->output()));
		$this->assertCount(2, $lines);
		$this->assertStringNotContainsString('[EXC]', $lines[0]);
		$this->assertStringContainsString('[EXC]', $lines[1]);
	}

	public function testAccessLogFilterAndStringXhrHeader(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '#health#', 60, debug: false);
		$output->line($this->access('/health'));
		$output->line($this->access('/home', headers: ['X-Requested-With' => 'xmlhttprequest']));

		$this->assertStringNotContainsString('/health', $buffer->output());
		$this->assertStringContainsString('[XHR]', $buffer->output());
	}

	public function testStartupMessageIsLeftToTheCommand(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: false);
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'FrankenPHP started 🐘',
		]));
		$output->line($this->entry([
			'logger' => 'frankenphp',
			'msg' => 'PHP warning',
		]));

		$this->assertSame("PHP warning\n", $buffer->output());
	}

	public function testDebugOutputPassesOtherJsonThrough(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: true);
		$line = $this->entry(['level' => 'debug', 'msg' => 'config']);
		$output->line($line);

		$this->assertSame($line . "\n", $buffer->output());
	}

	public function testErrorsGoToStderr(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: false);
		$output->line($this->entry([
			'level' => 'error',
			'msg' => 'startup failed',
			'error' => 'address in use',
		]));

		$this->assertSame('', $buffer->output());
		$this->assertStringContainsString('startup failed: address in use', $buffer->errorOutput());
	}

	public function testMalformedOutputPassesThrough(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new FrankenOutput($io, '', 60, debug: false);
		$output->line("plain <output>\n");
		$output->line($this->entry([
			'logger' => 'http.log.access',
			'msg' => 'handled request',
		]));

		$this->assertStringContainsString("plain <output>\n", $buffer->output());
		$this->assertStringContainsString('http.log.access', $buffer->output());
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
