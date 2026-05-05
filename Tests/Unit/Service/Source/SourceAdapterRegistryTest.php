<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Source;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use T3x\StaticHtmlImporter\Service\Source\CrawlAdapter;
use T3x\StaticHtmlImporter\Service\Source\LocalFilesAdapter;
use T3x\StaticHtmlImporter\Service\Source\PatternLibraryAdapter;
use T3x\StaticHtmlImporter\Service\Source\SourceAdapterRegistry;

final class SourceAdapterRegistryTest extends TestCase
{
    private SourceAdapterRegistry $registry;
    private LocalFilesAdapter $local;
    private CrawlAdapter $crawl;
    private PatternLibraryAdapter $pattern;

    protected function setUp(): void
    {
        $this->local = new LocalFilesAdapter();
        $this->crawl = new CrawlAdapter(httpClient: new MockHttpClient([]));
        $this->pattern = new PatternLibraryAdapter();
        $this->registry = new SourceAdapterRegistry($this->local, $this->crawl, $this->pattern);
    }

    public function testRoutesHttpToCrawler(): void
    {
        $route = $this->registry->resolve('http://example.com/');

        self::assertSame('crawl', $route['name']);
        self::assertSame($this->crawl, $route['adapter']);
        self::assertSame('http://example.com/', $route['source']);
    }

    public function testRoutesHttpsToCrawler(): void
    {
        $route = $this->registry->resolve('https://example.com/');

        self::assertSame('crawl', $route['name']);
    }

    public function testRoutesPatternLibraryPrefixAndStripsIt(): void
    {
        $route = $this->registry->resolve('pattern-library:/var/patterns');

        self::assertSame('pattern-library', $route['name']);
        self::assertSame($this->pattern, $route['adapter']);
        self::assertSame('/var/patterns', $route['source']);
    }

    public function testFallsBackToLocalForOrdinaryPaths(): void
    {
        $route = $this->registry->resolve('/var/www/site/static');

        self::assertSame('local', $route['name']);
        self::assertSame($this->local, $route['adapter']);
        self::assertSame('/var/www/site/static', $route['source']);
    }

    public function testFallsBackToLocalForRelativePaths(): void
    {
        $route = $this->registry->resolve('./relative/path');

        self::assertSame('local', $route['name']);
    }

    public function testFallsBackToLocalForUnknownScheme(): void
    {
        $route = $this->registry->resolve('ftp://example.com/file.html');

        self::assertSame('local', $route['name']);
    }

    public function testHttpRouteIsCaseInsensitive(): void
    {
        self::assertSame('crawl', $this->registry->resolve('HTTPS://example.com')['name']);
        self::assertSame('crawl', $this->registry->resolve('Http://example.com')['name']);
    }
}
