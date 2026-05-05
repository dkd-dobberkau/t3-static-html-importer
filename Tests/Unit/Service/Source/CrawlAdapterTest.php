<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;
use T3x\StaticHtmlImporter\Service\Source\CrawlAdapter;

final class CrawlAdapterTest extends TestCase
{
    public function testYieldsSinglePage(): void
    {
        $client = new MockHttpClient([
            new MockResponse('<html><body><h1>Home</h1></body></html>', [
                'response_headers' => ['content-type' => 'text/html; charset=utf-8'],
            ]),
        ]);
        $adapter = new CrawlAdapter(httpClient: $client);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(1, $docs);
        self::assertSame('index.html', $docs[0]->path);
        self::assertSame('https://example.com/', $docs[0]->metadata['url']);
        self::assertSame(0, $docs[0]->metadata['depth']);
    }

    public function testFollowsLinksWithinSameHost(): void
    {
        $client = new MockHttpClient([
            new MockResponse(
                '<html><body><a href="/about">About</a><a href="/contact">Contact</a></body></html>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
            new MockResponse('<html>About page</html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html>Contact page</html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);
        $adapter = new CrawlAdapter(httpClient: $client, maxDepth: 2);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(3, $docs);
        $paths = array_map(static fn (SourceDocument $d): string => $d->path, $docs);
        self::assertContains('about', $paths);
        self::assertContains('contact', $paths);
    }

    public function testRespectsDepthCap(): void
    {
        $client = new MockHttpClient([
            new MockResponse('<a href="/lvl1">L1</a>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<a href="/lvl2">L2</a>', ['response_headers' => ['content-type' => 'text/html']]),
            // /lvl2 should never be requested because depth=2 won't fetch beyond
        ]);
        $adapter = new CrawlAdapter(httpClient: $client, maxDepth: 1);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(2, $docs, 'depth=1 reaches start + lvl1, never lvl2');
        self::assertSame(1, $docs[1]->metadata['depth']);
    }

    public function testRespectsMaxPagesCap(): void
    {
        $client = new MockHttpClient([
            new MockResponse('<a href="/a">A</a><a href="/b">B</a><a href="/c">C</a>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html>A</html>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html>B</html>', ['response_headers' => ['content-type' => 'text/html']]),
            // /c should not be requested because cap is 2
        ]);
        $adapter = new CrawlAdapter(httpClient: $client, maxDepth: 5, maxPages: 2);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(2, $docs);
    }

    public function testSkipsExternalLinksWhenFollowExternalIsFalse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('<a href="https://other.com/x">External</a><a href="/local">Local</a>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html>local</html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);
        $adapter = new CrawlAdapter(httpClient: $client, maxDepth: 2);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(2, $docs);
        $hosts = array_map(static fn (SourceDocument $d): string => (string)parse_url($d->metadata['url'], PHP_URL_HOST), $docs);
        self::assertSame(['example.com', 'example.com'], $hosts);
    }

    public function testSkipsNonHtmlResponses(): void
    {
        $client = new MockHttpClient([
            new MockResponse(
                '<a href="/data.json">Data</a><a href="/page">Page</a>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
            new MockResponse('{"json":true}', ['response_headers' => ['content-type' => 'application/json']]),
            new MockResponse('<html>page</html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);
        $adapter = new CrawlAdapter(httpClient: $client);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(2, $docs);
        $paths = array_map(static fn (SourceDocument $d): string => $d->path, $docs);
        self::assertContains('page', $paths);
        self::assertNotContains('data.json', $paths);
    }

    public function testSkipsNonOkStatus(): void
    {
        $client = new MockHttpClient([
            new MockResponse('<a href="/missing">404</a><a href="/ok">OK</a>', ['response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('not found', ['http_code' => 404, 'response_headers' => ['content-type' => 'text/html']]),
            new MockResponse('<html>ok</html>', ['response_headers' => ['content-type' => 'text/html']]),
        ]);
        $adapter = new CrawlAdapter(httpClient: $client);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(2, $docs, 'start + /ok; /missing was 404 and is dropped');
    }

    public function testIgnoresMaltoTelJavascriptAndFragmentLinks(): void
    {
        $client = new MockHttpClient([
            new MockResponse(
                '<a href="mailto:x@y.com">m</a><a href="tel:1234">t</a><a href="javascript:foo()">j</a><a href="#section">f</a>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
        ]);
        $adapter = new CrawlAdapter(httpClient: $client);

        $docs = $this->collect($adapter, 'https://example.com/');

        self::assertCount(1, $docs);
    }

    public function testRejectsRelativeStartUrl(): void
    {
        $adapter = new CrawlAdapter(httpClient: new MockHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source must be an absolute URL');

        $this->collect($adapter, '/relative/path');
    }

    public function testRejectsNonHttpScheme(): void
    {
        $adapter = new CrawlAdapter(httpClient: new MockHttpClient([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only http/https');

        $this->collect($adapter, 'ftp://example.com/file.html');
    }

    /**
     * @return list<SourceDocument>
     */
    private function collect(CrawlAdapter $adapter, string $source): array
    {
        return iterator_to_array($adapter->read($source), false);
    }
}
