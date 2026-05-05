<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Analyzer;

use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\DomCrawler\Crawler;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;

/**
 * Segments a SourceDocument into ContentBlock candidates using deterministic
 * heuristics: HTML5 sectioning elements, `data-component`, `role`, BEM class
 * patterns. AI classification is layered on top by AiClassifier in step 5.
 *
 * Strategy: collect all matches under <body>, then keep only "leaf" matches
 * (matches that contain no other match). This skips outer containers like
 * <main> when its children are themselves blocks.
 */
final class StructuralAnalyzer
{
    private const XPATH_QUERY = '//section | //article | //header | //footer | //nav | //aside | //main | //*[@data-component] | //*[@role]';

    private const SEMANTIC_TAGS = ['header', 'footer', 'nav', 'aside', 'main'];

    public function __construct(
        private readonly BlockHasher $hasher,
    ) {
    }

    /**
     * @return list<ContentBlock>
     */
    public function analyze(SourceDocument $document): array
    {
        if (trim($document->html) === '') {
            return [];
        }

        $crawler = new Crawler();
        $crawler->addHtmlContent($document->html);
        $body = $crawler->filter('body')->getNode(0);
        if ($body === null) {
            return [];
        }

        $matches = $this->collectMatches($body);
        $leaves = $this->keepLeafMatches($matches);

        $blocks = [];
        foreach ($leaves as $node) {
            $blocks[] = $this->buildBlock($node);
        }
        return $blocks;
    }

    /**
     * @return list<DOMElement>
     */
    private function collectMatches(DOMNode $root): array
    {
        $doc = $root->ownerDocument;
        if ($doc === null) {
            return [];
        }
        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query('.' . self::XPATH_QUERY, $root);
        if ($nodes === false) {
            return [];
        }

        $matches = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $matches[] = $node;
            }
        }
        return $matches;
    }

    /**
     * @param list<DOMElement> $matches
     * @return list<DOMElement>
     */
    private function keepLeafMatches(array $matches): array
    {
        $leaves = [];
        foreach ($matches as $node) {
            $hasInner = false;
            foreach ($matches as $other) {
                if ($other !== $node && $this->isAncestor($node, $other)) {
                    $hasInner = true;
                    break;
                }
            }
            if (!$hasInner) {
                $leaves[] = $node;
            }
        }
        return $leaves;
    }

    private function isAncestor(DOMNode $maybeAncestor, DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent !== null) {
            if ($parent === $maybeAncestor) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    private function buildBlock(DOMElement $node): ContentBlock
    {
        $doc = $node->ownerDocument;
        $html = $doc !== null ? ($doc->saveHTML($node) ?: '') : '';

        return new ContentBlock(
            id: $this->hasher->hash($node),
            html: $html,
            tag: strtolower($node->tagName),
            candidateTypes: $this->candidateTypes($node),
            confidence: $this->confidence($node),
            attributes: $this->collectAttributes($node),
        );
    }

    /**
     * @return list<string>
     */
    private function candidateTypes(DOMElement $node): array
    {
        $types = [];

        $component = $node->getAttribute('data-component');
        if ($component !== '') {
            $types[] = $component;
        }

        $role = $node->getAttribute('role');
        if ($role !== '') {
            $types[] = 'role:' . $role;
        }

        $tag = strtolower($node->tagName);
        $types[] = match (true) {
            in_array($tag, self::SEMANTIC_TAGS, true) => $tag,
            $tag === 'section', $tag === 'article' => $this->guessFromContent($node),
            default => 'unknown',
        };

        $bem = $this->bemBlockName($node->getAttribute('class'));
        if ($bem !== null) {
            $types[] = 'bem:' . $bem;
        }

        return array_values(array_unique(array_filter($types, static fn (string $t): bool => $t !== '')));
    }

    private function guessFromContent(DOMElement $node): string
    {
        $hasImg = $node->getElementsByTagName('img')->length > 0;
        $hasHeading = $node->getElementsByTagName('h1')->length
            + $node->getElementsByTagName('h2')->length
            + $node->getElementsByTagName('h3')->length > 0;
        $hasParagraph = $node->getElementsByTagName('p')->length > 0;

        return match (true) {
            $hasImg && $hasParagraph => 'textmedia',
            $hasImg => 'image',
            $hasHeading && $hasParagraph => 'header_text',
            $hasParagraph => 'text',
            default => 'unknown',
        };
    }

    private function bemBlockName(string $classAttr): ?string
    {
        $classAttr = trim($classAttr);
        if ($classAttr === '') {
            return null;
        }
        foreach (preg_split('/\s+/', $classAttr) ?: [] as $token) {
            if (preg_match('/^([a-z][a-z0-9-]*)(?:__|--)/', $token, $m) === 1) {
                return $m[1];
            }
        }
        return null;
    }

    private function confidence(DOMElement $node): float
    {
        if ($node->hasAttribute('data-component')) {
            return 0.9;
        }
        if ($node->hasAttribute('role')) {
            return 0.75;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::SEMANTIC_TAGS, true)) {
            return 0.7;
        }
        if ($tag === 'section' || $tag === 'article') {
            return 0.55;
        }
        return 0.4;
    }

    /**
     * @return array<string, string>
     */
    private function collectAttributes(DOMElement $node): array
    {
        $out = [];
        if ($node->attributes !== null) {
            foreach ($node->attributes as $attr) {
                $out[$attr->name] = $attr->value;
            }
        }
        return $out;
    }
}
