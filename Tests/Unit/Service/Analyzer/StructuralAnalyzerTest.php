<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Analyzer;

use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;
use T3x\StaticHtmlImporter\Service\Analyzer\BlockHasher;
use T3x\StaticHtmlImporter\Service\Analyzer\StructuralAnalyzer;

final class StructuralAnalyzerTest extends TestCase
{
    private StructuralAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new StructuralAnalyzer(new BlockHasher());
    }

    public function testReturnsEmptyForEmptyHtml(): void
    {
        $blocks = $this->analyzer->analyze(new SourceDocument('x.html', ''));
        self::assertSame([], $blocks);
    }

    public function testFindsSemanticTags(): void
    {
        $blocks = $this->analyze('<header>H</header><footer>F</footer>');

        $tags = array_map(static fn (ContentBlock $b): string => $b->tag, $blocks);
        sort($tags);
        self::assertSame(['footer', 'header'], $tags);
    }

    public function testDataComponentTakesPriority(): void
    {
        $blocks = $this->analyze('<section data-component="hero"><p>Hi</p></section>');

        self::assertCount(1, $blocks);
        self::assertSame(0.9, $blocks[0]->confidence);
        self::assertContains('hero', $blocks[0]->candidateTypes);
    }

    public function testRoleProducesPenultimateConfidence(): void
    {
        $blocks = $this->analyze('<div role="navigation">Nav</div>');

        self::assertCount(1, $blocks);
        self::assertSame(0.75, $blocks[0]->confidence);
    }

    public function testSkipsContainerWhenChildIsAlsoAMatch(): void
    {
        $blocks = $this->analyze('<main><section><p>Hi</p></section></main>');

        self::assertCount(1, $blocks, 'main is a container, only the inner section should remain');
        self::assertSame('section', $blocks[0]->tag);
    }

    public function testGuessesTextmediaForImageWithParagraph(): void
    {
        $blocks = $this->analyze('<section><img src="x.jpg"><p>Caption</p></section>');

        self::assertContains('textmedia', $blocks[0]->candidateTypes);
    }

    public function testGuessesTextForParagraphOnlySection(): void
    {
        $blocks = $this->analyze('<section><p>Hi</p></section>');

        self::assertContains('text', $blocks[0]->candidateTypes);
    }

    public function testExtractsBemBlockName(): void
    {
        $blocks = $this->analyze('<section class="card card--featured"><p>Hi</p></section>');

        self::assertContains('bem:card', $blocks[0]->candidateTypes);
    }

    public function testIdenticalSiblingsCollapseToSameHash(): void
    {
        $blocks = $this->analyze(
            '<main>'
            . '<section class="card"><h2>A</h2><p>Body A</p></section>'
            . '<section class="card"><h2>B</h2><p>Body B</p></section>'
            . '</main>',
        );

        self::assertCount(2, $blocks);
        self::assertSame($blocks[0]->id, $blocks[1]->id, 'same structure should yield same hash');
    }

    /**
     * @return list<ContentBlock>
     */
    private function analyze(string $bodyHtml): array
    {
        $html = '<html><body>' . $bodyHtml . '</body></html>';
        return $this->analyzer->analyze(new SourceDocument('test.html', $html));
    }
}
