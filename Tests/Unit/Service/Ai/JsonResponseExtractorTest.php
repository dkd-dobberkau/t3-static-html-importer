<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Ai;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use T3x\StaticHtmlImporter\Service\Ai\JsonResponseExtractor;

final class JsonResponseExtractorTest extends TestCase
{
    private const SCHEMA = '{"type":"object","required":["type","confidence"],"properties":{"type":{"type":"string"},"confidence":{"type":"number"}}}';

    private JsonResponseExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new JsonResponseExtractor();
    }

    public function testExtractsPlainJson(): void
    {
        $payload = $this->extractor->extract('{"type":"text","confidence":0.8}', self::SCHEMA);

        self::assertSame(['type' => 'text', 'confidence' => 0.8], $payload);
    }

    public function testStripsCodeFences(): void
    {
        $content = "```json\n{\"type\":\"text\",\"confidence\":0.5}\n```";

        $payload = $this->extractor->extract($content, self::SCHEMA);

        self::assertSame('text', $payload['type']);
    }

    public function testStripsBareCodeFences(): void
    {
        $content = "```\n{\"type\":\"text\",\"confidence\":0.5}\n```";

        $payload = $this->extractor->extract($content, self::SCHEMA);

        self::assertSame(0.5, $payload['confidence']);
    }

    public function testRecoversJsonFromSurroundingProse(): void
    {
        $content = 'Sure, here is the answer: {"type":"text","confidence":0.7} Hope this helps!';

        $payload = $this->extractor->extract($content, self::SCHEMA);

        self::assertSame('text', $payload['type']);
    }

    public function testThrowsOnNonJsonContent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a JSON object');

        $this->extractor->extract('I cannot help with that.', self::SCHEMA);
    }

    public function testThrowsWhenRequiredKeyIsMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing required field "confidence"');

        $this->extractor->extract('{"type":"text"}', self::SCHEMA);
    }

    public function testTolerantWhenSchemaHasNoRequiredArray(): void
    {
        $payload = $this->extractor->extract('{"value":42}', '{"type":"object"}');

        self::assertSame(['value' => 42], $payload);
    }
}
