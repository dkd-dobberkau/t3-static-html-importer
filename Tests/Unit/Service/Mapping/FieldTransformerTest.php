<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Mapping;

use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\AssetMetadata;
use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierInterface;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;
use T3x\StaticHtmlImporter\Service\Mapping\FieldTransformer;

final class FieldTransformerTest extends TestCase
{
    private FieldTransformer $transformer;
    private ImportMapping $mapping;

    protected function setUp(): void
    {
        $this->transformer = new FieldTransformer(new AiClassifierMock());
        $this->mapping = new ImportMapping(cType: 'textmedia');
    }

    public function testExtractsHeaderTextFromH1(): void
    {
        $block = $this->block('<section><h1>Hello world</h1><p>body</p></section>');

        $value = $this->transformer->transform($block, $this->mapping, $this->field('header'));

        self::assertSame('Hello world', $value);
    }

    public function testCollapsesWhitespaceInString(): void
    {
        $block = $this->block("<section><h2>  Multi\n   line\n   header  </h2></section>");

        self::assertSame(
            'Multi line header',
            $this->transformer->transform($block, $this->mapping, $this->field('header')),
        );
    }

    public function testExtractsBodytextFromFirstParagraph(): void
    {
        $block = $this->block('<section><p>The body text</p><p>Second</p></section>');

        self::assertSame(
            'The body text',
            $this->transformer->transform($block, $this->mapping, $this->field('bodytext')),
        );
    }

    public function testExtractsImageSrc(): void
    {
        $block = $this->block('<section><img src="/uploads/hero.jpg" alt="Hero"></section>');

        self::assertSame(
            '/uploads/hero.jpg',
            $this->transformer->transform($block, $this->mapping, $this->field('image')),
        );
    }

    public function testExtractsLinkHref(): void
    {
        $block = $this->block('<section><a href="https://example.com">Click</a></section>');

        self::assertSame(
            'https://example.com',
            $this->transformer->transform($block, $this->mapping, $this->field('link')),
        );
    }

    public function testExtractsHtmlPreservingInlineMarkup(): void
    {
        $block = $this->block('<section><p>Some <strong>bold</strong> text</p></section>');

        $value = $this->transformer->transform($block, $this->mapping, $this->field('bodytext', 'html'));

        self::assertSame('Some <strong>bold</strong> text', $value);
    }

    public function testExtractsIntegerFromText(): void
    {
        $block = $this->block('<section><p>Order #42 confirmed</p></section>');

        self::assertSame(
            '42',
            $this->transformer->transform($block, $this->mapping, $this->field('count', 'int')),
        );
    }

    public function testExtractsNegativeInteger(): void
    {
        $block = $this->block('<section><p>Delta -7 units</p></section>');

        self::assertSame(
            '-7',
            $this->transformer->transform($block, $this->mapping, $this->field('delta', 'int')),
        );
    }

    public function testExtractsDateFromTimeDatetimeAttribute(): void
    {
        $block = $this->block('<section><time datetime="2026-04-12T10:00:00Z">Last April</time></section>');

        self::assertSame(
            '2026-04-12',
            $this->transformer->transform($block, $this->mapping, $this->field('published', 'date')),
        );
    }

    public function testExtractsDateFromHumanText(): void
    {
        $block = $this->block('<section class="event"><span class="published">2026-01-15</span></section>');

        self::assertSame(
            '2026-01-15',
            $this->transformer->transform($block, $this->mapping, $this->field('published', 'date')),
        );
    }

    public function testFallsBackToAiWhenDeterministicReturnsNull(): void
    {
        // AiClassifierMock reads marker comments like "<!--header: ...-->"
        $block = $this->block('<section><div>no headings here</div><!--header: From AI Mock --></section>');

        self::assertSame(
            'From AI Mock',
            $this->transformer->transform($block, $this->mapping, $this->field('header')),
        );
    }

    public function testReturnsNullWhenBlockHtmlIsEmpty(): void
    {
        $block = new ContentBlock(
            id: 'h0',
            html: '',
            tag: 'div',
            candidateTypes: ['x'],
            confidence: 0.5,
        );

        self::assertNull(
            $this->transformer->transform($block, $this->mapping, $this->field('header')),
        );
    }

    public function testReturnsNullWhenAiAlsoFails(): void
    {
        // AI throws -> transform should return null, not bubble.
        $alwaysFailingAi = new class implements AiClassifierInterface {
            public function classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult
            {
                throw new \RuntimeException('boom');
            }
            public function extractFieldValue(string $domFragment, FieldDefinition $field): ?string
            {
                throw new \RuntimeException('boom');
            }
            public function enrichAssetMetadata(string $imagePath): AssetMetadata
            {
                throw new \RuntimeException('boom');
            }
        };

        $transformer = new FieldTransformer($alwaysFailingAi);
        $block = $this->block('<section><span>nothing matching</span></section>');

        self::assertNull(
            $transformer->transform($block, $this->mapping, $this->field('header')),
        );
    }

    public function testFindsValueViaGenericClassSelector(): void
    {
        $block = $this->block('<section><div class="caption">Photo caption</div></section>');

        self::assertSame(
            'Photo caption',
            $this->transformer->transform($block, $this->mapping, $this->field('caption')),
        );
    }

    private function block(string $html): ContentBlock
    {
        return new ContentBlock(
            id: 'test-hash',
            html: $html,
            tag: 'section',
            candidateTypes: ['textmedia'],
            confidence: 0.5,
        );
    }

    private function field(string $name, string $type = 'string'): FieldDefinition
    {
        return new FieldDefinition(name: $name, description: $name . ' field', type: $type);
    }
}
