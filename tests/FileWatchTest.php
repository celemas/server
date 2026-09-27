<?php

declare(strict_types=1);

namespace Celema\Server\Tests;

use Celema\Server\FileWatch;
use Celema\Server\WatchGlob;
use PHPUnit\Framework\TestCase;

final class FileWatchTest extends TestCase
{
	private string $root;
	private string $cwd;

	protected function setUp(): void
	{
		$root = tempnam(sys_get_temp_dir(), 'celema-watch-');
		$this->assertIsString($root);
		unlink($root);
		mkdir($root);
		$this->root = (string) realpath($root);
		$this->cwd = (string) getcwd();
		chdir($this->root);
	}

	protected function tearDown(): void
	{
		chdir($this->cwd);
		self::remove($this->root);
	}

	public function testGlobsMatchAcrossAndWithinDirectories(): void
	{
		$glob = WatchGlob::compile('./src/**/*.php');

		$this->assertSame('src', $glob->base);
		$this->assertTrue($glob->matches('src/App.php'));
		$this->assertTrue($glob->matches('src/Http/Controller/Page.php'));
		$this->assertFalse($glob->matches('src/App.phpx'));
		$this->assertFalse($glob->matches('lib/App.php'));

		$glob = WatchGlob::compile('views/*.ph?');

		$this->assertTrue($glob->matches('views/page.php'));
		$this->assertFalse($glob->matches('views/partials/nav.php'));
		$this->assertFalse($glob->deep);
		$this->assertTrue(WatchGlob::compile('views/**/*.php')->deep);
		$this->assertTrue(WatchGlob::compile('views/*/page.php')->deep);
		$this->assertSame('.', WatchGlob::compile('**/*.css')->base);
		$this->assertTrue(WatchGlob::compile('**/*.css')->matches('app.css'));
		$this->assertTrue(WatchGlob::compile('/var/www/**')->matches('/var/www/a/b.txt'));
	}

	public function testPlainDirectoryWatchesItsContents(): void
	{
		$this->write('views/partials/nav.php');

		$glob = WatchGlob::compile('views');

		$this->assertSame('views', $glob->base);
		$this->assertTrue($glob->matches('views/partials/nav.php'));
	}

	public function testReportsAddedModifiedAndRemovedFiles(): void
	{
		$this->write('src/App.php');
		$this->write('src/Old.php');
		$this->write('src/notes.txt');
		$watch = new FileWatch(['src/**/*.php']);

		$this->assertSame([], $watch->changes());

		$this->write('src/App.php', 'changed content');
		$this->write('src/Http/New.php');
		unlink('src/Old.php');
		$this->write('src/notes.txt', 'unwatched change');
		$changes = $watch->changes();
		sort($changes);

		$this->assertSame(['src/App.php', 'src/Http/New.php', 'src/Old.php'], $changes);
		$this->assertSame([], $watch->changes());
	}

	public function testDetectsSameSizeChangesWithinOneSecond(): void
	{
		$this->write('views/page.php', 'aaaa');
		$mtime = (int) filemtime('views/page.php');
		$watch = new FileWatch(['views/**/*.php']);

		$this->write('views/page.php', 'bbbb');
		touch('views/page.php', $mtime);

		$this->assertSame(['views/page.php'], $watch->changes());
	}

	public function testGlobsCoverDirectoriesOnlyForAllTheirContents(): void
	{
		$this->assertTrue(WatchGlob::compile('cache/**')->coversDirectory('cache'));
		$this->assertTrue(WatchGlob::compile('cache/')->coversDirectory('cache'));
		$this->assertTrue(WatchGlob::compile('**/cache/**')->coversDirectory('src/cache'));
		$this->assertFalse(WatchGlob::compile('cache/*.php')->coversDirectory('cache'));
		$this->assertFalse(WatchGlob::compile('cache/**/*.php')->coversDirectory('cache'));
		$this->assertFalse(WatchGlob::compile('cache/**')->coversDirectory('src'));
	}

	public function testNegatedPatternsExcludeFiles(): void
	{
		$watch = new FileWatch(['src/**/*.php', '!src/**/*.cache.php', '!src/generated/**', '!src/tmp/']);

		$this->write('src/App.php');
		$this->write('src/Views.cache.php');
		$this->write('src/generated/Proxy.php');
		$this->write('src/tmp/Compiled.php');

		$this->assertSame(['src/App.php'], $watch->changes());
	}

	public function testShallowPatternsIgnoreSubdirectories(): void
	{
		$watch = new FileWatch(['views/*.php']);

		$this->write('views/page.php');
		$this->write('views/partials/nav.php');

		$this->assertSame(['views/page.php'], $watch->changes());
	}

	public function testSkipsDependencyAndHiddenDirectoriesUnlessTargeted(): void
	{
		$this->write('app.php');
		$watch = new FileWatch(['**/*.php', 'vendor/acme/lib/**/*.php']);

		$this->write('node_modules/pkg/index.php');
		$this->write('.cache/compiled.php');
		$this->write('vendor/other/lib.php');
		$this->write('vendor/acme/lib/src/Lib.php');

		$this->assertSame(['vendor/acme/lib/src/Lib.php'], $watch->changes());
	}

	public function testFollowsSymlinkedDirectories(): void
	{
		$this->write('packages/cms/src/Cms.php');
		mkdir('vendor/cosray', recursive: true);
		symlink($this->root . '/packages/cms', 'vendor/cosray/cms');
		// A loop back to the package root must not recurse forever.
		symlink($this->root . '/packages/cms', 'packages/cms/src/loop');
		$watch = new FileWatch(['vendor/cosray/cms/**/*.php']);

		$this->write('packages/cms/src/Cms.php', 'changed content');

		$this->assertSame(['vendor/cosray/cms/src/Cms.php'], $watch->changes());
	}

	private function write(string $path, string $content = 'content'): void
	{
		if (!is_dir(dirname($path))) {
			mkdir(dirname($path), recursive: true);
		}

		file_put_contents($path, $content);
	}

	private static function remove(string $path): void
	{
		if (is_link($path) || is_file($path)) {
			unlink($path);

			return;
		}

		foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
			self::remove("{$path}/{$entry}");
		}

		rmdir($path);
	}
}
