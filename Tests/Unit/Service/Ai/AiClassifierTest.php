<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Ai;

use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;

/**
 * Pins down the deterministic AiClassifierMock behaviour. Other components rely
 * on these guarantees in their own tests; the production AiClassifier is not
 * tested here yet because its dispatch() to b13/aim is intentionally a stub
 * (see PROJECT_BRIEF.md and issue #5).
 */
final class AiClassifierTest extends TestCase
{
    private AiClassifierMock $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AiClassifierMock();
    }

    public function testClassifyBlockPicksFirstCandidate(): void
    {
        $result = $this->classifier->classifyBlock('<p>Hi</p>', ['textmedia', 'text']);

        self::assertSame('textmedia', $result->type);
        self::assertSame(0.5, $result->confidence);
        self::assertStringContainsString('textmedia', $result->rationale);
    }

    public function testClassifyBlockFallsBackToUnknownWhenNoCandidates(): void
    {
        $result = $this->classifier->classifyBlock('<p>Hi</p>', []);

        self::assertSame('unknown', $result->type);
    }

    public function testExtractFieldValueReadsMarkerComment(): void
    {
        $field = new FieldDefinition(name: 'header', description: 'h', type: 'string');

        $value = $this->classifier->extractFieldValue('<p>before</p><!--header: Hello -->', $field);

        self::assertSame('Hello', $value);
    }

    public function testExtractFieldValueReturnsNullWhenMarkerMissing(): void
    {
        $field = new FieldDefinition(name: 'absent', description: 'x', type: 'string');

        self::assertNull($this->classifier->extractFieldValue('<p>nothing here</p>', $field));
    }

    public function testEnrichAssetMetadataDerivesTitleFromFilename(): void
    {
        $meta = $this->classifier->enrichAssetMetadata('/some/path/hero-banner.jpg');

        self::assertSame('Hero Banner', $meta->title);
        self::assertSame('Hero Banner', $meta->altText);
        self::assertContains('mock', $meta->tags);
    }
}
