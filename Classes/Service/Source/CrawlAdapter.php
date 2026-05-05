<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

use DOMElement;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;
use Throwable;

/**
 * Crawls an HTTP(S) site and yields one SourceDocument per fetched HTML page.
 *
 * Strategy: BFS from the start URL, configurable depth and page caps. Same
 * origin only by default; pass `followExternal=true` to open it up. Skips
 * non-HTML responses, non-2xx status codes, malformed URLs, mailto/tel/
 * javascript hrefs, and fragment-only links. Resolves relative URLs against
 * the page's base.
 *
 * @todo robots.txt and rate limiting are out of scope here; document for
 *       operators that they must respect target-site policies themselves.
 */
final class CrawlAdapter implements SourceAdapterInterface
{
    public const DEFAULT_MAX_DEPTH = 2;
    public const DEFAULT_MAX_PAGES = 100;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private readonly int $maxPages = self::DEFAULT_MAX_PAGES,
        private readonly bool $followExternal = false,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable
    {
        $startUrl = $this->validateUrl($source);
        $startHost = (string)parse_url($startUrl, PHP_URL_HOST);

        /** @var list<array{url: string, depth: int}> $queue */
        $queue = [['url' => $startUrl, 'depth' => 0]];
        $visited = [];
        $pageCount = 0;

        while ($queue !== [] && $pageCount < $this->maxPages) {
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = $item['depth'];

            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            try {
                $response = $this->httpClient->request('GET', $url, ['max_redirects' => 5]);
                $status = $response->getStatusCode();
                if ($status < 200 || $status >= 300) {
                    continue;
                }
                $headers = $response->getHeaders(false);
                $contentType = strtolower($headers['content-type'][0] ?? '');
                if (!str_contains($contentType, 'text/html')) {
                    continue;
                }
                $content = $response->getContent(false);
            } catch (Throwable) {
                continue;
            }

            yield new SourceDocument(
                path: $this->urlToPath($url),
                html: $content,
                metadata: [
                    'url' => $url,
                    'depth' => $depth,
                    'status' => $status,
                ],
            );
            $pageCount++;

            if ($depth >= $this->maxDepth) {
                continue;
            }

            foreach ($this->extractLinks($content, $url, $startHost) as $link) {
                if (!isset($visited[$link])) {
                    $queue[] = ['url' => $link, 'depth' => $depth + 1];
                }
            }
        }
    }

    private function validateUrl(string $source): string
    {
        $parsed = parse_url($source);
        if ($parsed === false || !isset($parsed['scheme'], $parsed['host'])) {
            throw new InvalidArgumentException(sprintf('Source must be an absolute URL: %s', $source));
        }
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf('Only http/https URLs are supported: %s', $source));
        }
        return $source;
    }

    private function urlToPath(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return 'index.html';
        }
        $clean = ltrim($path, '/');
        return $clean === '' ? 'index.html' : $clean;
    }

    /**
     * @return iterable<string>
     */
    private function extractLinks(string $html, string $baseUrl, string $sameHost): iterable
    {
        $crawler = new Crawler();
        try {
            $crawler->addHtmlContent($html);
        } catch (Throwable) {
            return;
        }
        foreach ($crawler->filter('a[href]') as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }
            $lowerHref = strtolower($href);
            if (str_starts_with($lowerHref, 'javascript:')
                || str_starts_with($lowerHref, 'mailto:')
                || str_starts_with($lowerHref, 'tel:')
                || str_starts_with($lowerHref, 'data:')
            ) {
                continue;
            }

            $absolute = $this->resolveUrl($baseUrl, $href);
            if ($absolute === null) {
                continue;
            }
            if (!$this->followExternal && parse_url($absolute, PHP_URL_HOST) !== $sameHost) {
                continue;
            }
            yield preg_replace('/#.*$/', '', $absolute) ?? $absolute;
        }
    }

    private function resolveUrl(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $baseParts = parse_url($base);
        if ($baseParts === false || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $scheme = $baseParts['scheme'];
        $host = $baseParts['host'];
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return sprintf('%s://%s%s%s', $scheme, $host, $port, $href);
        }

        $basePath = $baseParts['path'] ?? '/';
        $baseDir = preg_replace('#/[^/]*$#', '', $basePath);
        if (!is_string($baseDir)) {
            $baseDir = '';
        }
        $combined = ($baseDir === '' ? '' : rtrim($baseDir, '/')) . '/' . $href;

        $segments = explode('/', $combined);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
                continue;
            }
            $resolved[] = $segment;
        }
        return sprintf('%s://%s%s/%s', $scheme, $host, $port, implode('/', $resolved));
    }
}
