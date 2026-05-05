<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;
use T3x\StaticHtmlImporter\Service\Source\LocalFilesAdapter;

final class LocalFilesAdapterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/t3shi-tests-' . uniqid('', true);
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testYieldsHtmlFilesRecursively(): void
    {
        $this->writeFile('a.html', '<p>A</p>');
        $this->writeFile('sub/b.html', '<p>B</p>');

        $docs = $this->collect();

        self::assertCount(2, $docs);
        $paths = array_map(static fn (SourceDocument $d): string => $d->path, $docs);
        sort($paths);
        self::assertSame(['a.html', 'sub/b.html'], $paths);
    }

    public function testIsCaseInsensitiveOnExtension(): void
    {
        $this->writeFile('a.HTML', '<p>A</p>');
        $this->writeFile('b.HTM', '<p>B</p>');

        self::assertCount(2, $this->collect());
    }

    public function testFiltersNonHtmlFiles(): void
    {
        $this->writeFile('a.html', '<p>A</p>');
        $this->writeFile('b.txt', 'plain text');
        $this->writeFile('c.css', 'body{}');

        $docs = $this->collect();
        self::assertCount(1, $docs);
        self::assertSame('a.html', $docs[0]->path);
    }

    public function testCarriesMetadata(): void
    {
        $this->writeFile('a.html', '<p>A</p>');

        $docs = $this->collect();

        self::assertArrayHasKey('absolute_path', $docs[0]->metadata);
        self::assertArrayHasKey('mtime', $docs[0]->metadata);
        self::assertArrayHasKey('size', $docs[0]->metadata);
        self::assertSame(8, $docs[0]->metadata['size']);
    }

    public function testThrowsOnMissingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source is not a directory');

        $this->collect('/nonexistent/path');
    }

    public function testThrowsWhenSourceIsAFile(): void
    {
        $this->writeFile('a.html', '<p>A</p>');

        $this->expectException(InvalidArgumentException::class);

        $this->collect($this->root . '/a.html');
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
    private function collect(?string $source = null): array
    {
        $adapter = new LocalFilesAdapter();
        return iterator_to_array($adapter->read($source ?? $this->root), false);
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
