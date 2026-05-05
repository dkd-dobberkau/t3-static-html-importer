<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;
use T3x\StaticHtmlImporter\Service\Source\PatternLibraryAdapter;

final class PatternLibraryAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/t3shi-pattern-' . uniqid('', true);
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testYieldsComponentWithoutVariant(): void
    {
        $this->writeFile('card/index.html', '<div class="card">card</div>');

        $docs = $this->collect();

        self::assertCount(1, $docs);
        self::assertSame('card', $docs[0]->metadata['component']);
        self::assertNull($docs[0]->metadata['variant']);
        self::assertStringContainsString('card', $docs[0]->html);
    }

    public function testYieldsComponentVariants(): void
    {
        $this->writeFile('card/index.html', '<div class="card">default</div>');
        $this->writeFile('card/featured/index.html', '<div class="card card--featured">featured</div>');
        $this->writeFile('card/compact/index.html', '<div class="card card--compact">compact</div>');

        $docs = $this->collect();

        self::assertCount(3, $docs);
        $variants = array_map(static fn (SourceDocument $d): ?string => $d->metadata['variant'], $docs);
        sort($variants);
        self::assertSame([null, 'compact', 'featured'], $variants);
    }

    public function testYieldsMultipleComponents(): void
    {
        $this->writeFile('card/index.html', '<div>card</div>');
        $this->writeFile('hero/index.html', '<section>hero</section>');
        $this->writeFile('header/index.html', '<header>header</header>');

        $docs = $this->collect();
        $components = array_map(static fn (SourceDocument $d): string => (string)$d->metadata['component'], $docs);
        sort($components);

        self::assertSame(['card', 'header', 'hero'], $components);
    }

    public function testIgnoresDirectoriesWithoutIndexHtml(): void
    {
        $this->writeFile('card/notes.txt', 'just notes');
        $this->writeFile('hero/index.html', '<section>hero</section>');

        $docs = $this->collect();

        self::assertCount(1, $docs);
        self::assertSame('hero', $docs[0]->metadata['component']);
    }

    public function testSkipsDotfilesAndDotDirs(): void
    {
        $this->writeFile('.hidden/index.html', 'should be skipped');
        $this->writeFile('card/index.html', '<div>card</div>');

        $docs = $this->collect();

        self::assertCount(1, $docs);
        self::assertSame('card', $docs[0]->metadata['component']);
    }

    public function testSkipsSymlinkedComponentDir(): void
    {
        $external = sys_get_temp_dir() . '/t3shi-ext-' . uniqid('', true);
        mkdir($external);
        file_put_contents($external . '/index.html', 'external');
        symlink($external, $this->root . '/evil');
        $this->writeFile('real/index.html', 'real');

        try {
            $docs = $this->collect();
            $components = array_map(static fn (SourceDocument $d): string => (string)$d->metadata['component'], $docs);

            self::assertSame(['real'], $components);
        } finally {
            @unlink($external . '/index.html');
            @rmdir($external);
        }
    }

    public function testSkipsOversizedFiles(): void
    {
        $this->writeFile('card/index.html', str_repeat('x', 2048));
        $this->writeFile('hero/index.html', '<small>ok</small>');

        $adapter = new PatternLibraryAdapter(maxFileSize: 1024);
        $docs = iterator_to_array($adapter->read($this->root), false);
        $components = array_map(static fn (SourceDocument $d): string => (string)$d->metadata['component'], $docs);

        self::assertSame(['hero'], $components);
    }

    public function testThrowsOnMissingSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PatternLibraryAdapter())->read('/nonexistent/path')->current();
    }

    private function writeFile(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        file_put_contents($path, $content);
    }

    /**
     * @return list<SourceDocument>
     */
    private function collect(): array
    {
        return iterator_to_array((new PatternLibraryAdapter())->read($this->root), false);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        rmdir($path);
    }
}
