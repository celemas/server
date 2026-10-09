<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Console\Buffer;
use Celema\Console\Io;
use Celema\Server\PhpOutput;
use PHPUnit\Framework\TestCase;

final class PhpOutputTest extends TestCase
{
	private const string TIMESTAMP = '[Sun Jul 20 17:12:05 2026] ';

	public function testRequestLineRenders(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /foo\n");

		$this->assertMatchesRegularExpression(
			'#^\d{2}:\d{2}:\d{2}\.\d{2} 200 GET /foo \.+ 0\.00016s\n$#',
			$buffer->output(),
		);
	}

	public function testRequestLineFillsTheTerminalWidth(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /foo\n");

		$this->assertSame(60, mb_strwidth(rtrim($buffer->output())));
	}

	public function testRequestTimeAndDurationAreDimmed(): void
	{
		$buffer = new Buffer(colors: true);
		new PhpOutput(new Io($buffer), '', 60)->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /foo\n");

		$this->assertMatchesRegularExpression(
			'#^\033\[2m\d{2}:\d{2}:\d{2}\.\d{2}\033\[0m .+ \033\[2m0\.00016s\033\[0m\n$#',
			$buffer->output(),
		);
	}

	public function testRequestLineShowsExceptionAndXhrFlags(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request 500 POST 0.20000 ex /api\n");

		$this->assertStringContainsString('[EXC][XHR] 0.20000s', $buffer->output());
	}

	public function testRequestUrlIsDecodedAndPrintsMarkupLiterally(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP
			. "celema-request 200 GET 0.00016 -- /%3Cred%3Etest\n");

		$this->assertStringContainsString('/<red>test', $buffer->output());
	}

	public function testFilterHidesMatchingRequests(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new PhpOutput($io, '#^/health#', 60);
		$output->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /health\n");
		$output->line(self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /home\n");

		$this->assertStringNotContainsString('/health', $buffer->output());
		$this->assertStringContainsString('/home', $buffer->output());
	}

	public function testConnectionLinesAreHidden(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new PhpOutput($io, '', 60);
		$output->line(self::TIMESTAMP . "127.0.0.1:54652 Accepted\n");
		$output->line(self::TIMESTAMP . "127.0.0.1:54652 [200]: GET /favicon.ico\n");
		$output->line(self::TIMESTAMP . "[::1]:54652 Accepted\n");
		$output->line(self::TIMESTAMP . "[::1]:54652 Closing\n");

		$this->assertSame('', $buffer->output());
	}

	public function testLinesOfMultipleProcessesRenderLikeOthers(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new PhpOutput($io, '', 60);
		$output->line('[18018] ' . self::TIMESTAMP . "127.0.0.1:60538 Accepted\n");
		$output->line('[18018] ' . self::TIMESTAMP . "celema-request 200 GET 0.00016 -- /foo\n");
		$output->line('[18018] ' . self::TIMESTAMP . "127.0.0.1:60538 [200]: GET /foo\n");
		$output->line('[18018] ' . self::TIMESTAMP . "127.0.0.1:60538 Closing\n");
		$output->line('[18020] ' . self::TIMESTAMP . "Notice: something\n");

		$this->assertMatchesRegularExpression(
			'#^\d{2}:\d{2}:\d{2}\.\d{2} 200 GET /foo \.+ 0\.00016s\nNotice: something\n$#',
			$buffer->output(),
		);
	}

	public function testPassthroughTrimsTheTimestamp(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "PHP Warning:  Undefined variable \$x\n");

		$this->assertSame("PHP Warning:  Undefined variable \$x\n", $buffer->output());
	}

	public function testStartupMessageIsLeftToTheCommand(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		$output = new PhpOutput($io, '', 60);
		$output->line(self::TIMESTAMP . "PHP 8.5.8 Development Server (http://localhost:1983) started\n");
		$output->line('[18020] ' . self::TIMESTAMP . "PHP 8.5.8 Development Server (http://localhost:1983) started\n");

		$this->assertSame('', $buffer->output());
	}

	public function testPassthroughIsEscaped(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line("Xdebug: \033[31mfailed\033[0m in <red>module</red>\n");

		$this->assertSame("Xdebug: [31mfailed[0m in <red>module</red>\n", $buffer->output());
	}

	public function testMalformedRequestLinePrintsLiterally(): void
	{
		$buffer = new Buffer();
		$io = new Io($buffer);
		new PhpOutput($io, '', 60)->line(self::TIMESTAMP . "celema-request oops\n");

		$this->assertSame("celema-request oops\n", $buffer->output());
	}
}
