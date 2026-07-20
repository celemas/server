<?php

declare(strict_types=1);

namespace Celema\Core\Tests;

use Celema\Console\BufferedIo;
use Celema\Core\Server\PhpOutput;

final class PhpOutputTest extends TestCase
{
	private const string TIMESTAMP = '[Sun Jul 20 17:12:05 2026] ';

	public function testRequestLineRenders(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /foo\n");

		$this->assertMatchesRegularExpression(
			'#^\d{2}:\d{2}:\d{2}\.\d{2} 200 GET /foo \.+ 0\.00016s\n$#',
			$io->output(),
		);
	}

	public function testRequestLineFillsTheTerminalWidth(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /foo\n");

		$this->assertSame(60, mb_strwidth(rtrim($io->output())));
	}

	public function testRequestLineShowsExceptionAndXhrFlags(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request 500 POST 0.20000 ex /api\n");

		$this->assertStringContainsString('[EXC][XHR] 0.20000s', $io->output());
	}

	public function testRequestUrlIsDecodedAndPrintsMarkupLiterally(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP
		. "celema-request 200 GET 0.00016 -- /%3Cred%3Etest\n");

		$this->assertStringContainsString('/<red>test', $io->output());
	}

	public function testFilterHidesMatchingRequests(): void
	{
		$io = new BufferedIo();
		$output = new PhpOutput($io, '#^/health#', 60);
		$output->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /health\n");
		$output->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /home\n");

		$this->assertStringNotContainsString('/health', $io->output());
		$this->assertStringContainsString('/home', $io->output());
	}

	public function testConnectionLinesAreHidden(): void
	{
		$io = new BufferedIo();
		$output = new PhpOutput($io, '', 60);
		$output->line(self::TIMESTAMP . "127.0.0.1:54652 Accepted\n");
		$output->line(self::TIMESTAMP . "127.0.0.1:54652 [200]: GET /favicon.ico\n");
		$output->line(self::TIMESTAMP . "[::1]:54652 Accepted\n");
		$output->line(self::TIMESTAMP . "[::1]:54652 Closing\n");

		$this->assertSame('', $io->output());
	}

	public function testPassthroughTrimsTheTimestamp(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line(
			self::TIMESTAMP . "PHP 8.5.8 Development Server (http://localhost:1983) started\n",
		);

		$this->assertSame(
			"PHP 8.5.8 Development Server (http://localhost:1983) started\n",
			$io->output(),
		);
	}

	public function testPassthroughIsEscaped(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line("Xdebug: \033[31mfailed\033[0m in <red>module</red>\n");

		$this->assertSame("Xdebug: [31mfailed[0m in <red>module</red>\n", $io->output());
	}

	public function testMalformedRequestLinePrintsLiterally(): void
	{
		$io = new BufferedIo();
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request oops\n");

		$this->assertSame("celema-request oops\n", $io->output());
	}
}
