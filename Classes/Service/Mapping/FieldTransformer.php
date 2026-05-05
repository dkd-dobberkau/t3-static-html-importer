<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Mapping;

use Symfony\Component\DomCrawler\Crawler;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierInterface;
use Throwable;

/**
 * Coerces a fragment of a ContentBlock into a tt_content column value.
 *
 * Strategy: deterministic extraction via DomCrawler first, AI fallback via
 * `AiClassifierInterface::extractFieldValue` only when the deterministic path
 * yields null. Per FieldDefinition::$type the result is normalised:
 *   - string: text content, trimmed and whitespace-collapsed
 *   - html:   inner HTML preserved (RteHtmlParser cleanup deferred to TYPO3
 *             integration; @todo)
 *   - int:    first signed/unsigned integer match
 *   - date:   `Y-m-d` from `strtotime()` or `<time datetime>` attribute
 *
 * Field selectors are guessed from FieldDefinition::$name (h1-h3 for "header",
 * `<p>` for "bodytext", `<img>` for "image", etc.) plus generic
 * `.{name}` and `[data-field="{name}"]` fallbacks.
 */
final class FieldTransformer implements FieldTransformerInterface
{
    public function __construct(
        private readonly AiClassifierInterface $ai,
    ) {
    }

    public function transform(ContentBlock $block, ImportMapping $mapping, FieldDefinition $field): ?string
    {
        unset($mapping); // selector targets the block, not its fields; reserved for future use

        if (trim($block->html) === '') {
            return null;
        }

        $crawler = $this->loadCrawler($block->html);
        if ($crawler === null) {
            return $this->aiFallback($block, $field);
        }

        $deterministic = $this->extractDeterministic($crawler, $field);
        if ($deterministic !== null) {
            return $deterministic;
        }

        return $this->aiFallback($block, $field);
    }

    private function aiFallback(ContentBlock $block, FieldDefinition $field): ?string
    {
        try {
            return $this->ai->extractFieldValue($block->html, $field);
        } catch (Throwable) {
            return null;
        }
    }

    private function loadCrawler(string $html): ?Crawler
    {
        try {
            $crawler = new Crawler();
            $crawler->addHtmlContent($html);
            return $crawler;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractDeterministic(Crawler $crawler, FieldDefinition $field): ?string
    {
        return match ($field->type) {
            'string' => $this->extractString($crawler, $field),
            'html' => $this->extractHtml($crawler, $field),
            'int' => $this->extractInt($crawler, $field),
            'date' => $this->extractDate($crawler, $field),
            'image' => $this->extractImage($crawler, $field),
            default => null,
        };
    }

    /**
     * Returns the src of the relevant `<img>` tag. Used by `image`-type fields
     * so the value can be routed through FalImporter downstream.
     */
    private function extractImage(Crawler $crawler, FieldDefinition $field): ?string
    {
        // Field-name-specific scoping (e.g. `.hero-image img`)
        foreach ($this->selectorsFor($field) as $selector) {
            $scoped = $this->safeFilter($crawler, $selector);
            if ($scoped->count() > 0) {
                $img = $scoped->filter('img')->first();
                if ($img->count() > 0) {
                    $src = $img->attr('src');
                    if ($src !== null && $src !== '') {
                        return $src;
                    }
                }
            }
        }
        // Fallback: first <img> anywhere in the block
        $first = $this->safeFilter($crawler, 'img')->first();
        if ($first->count() > 0) {
            $src = $first->attr('src');
            return $src !== null && $src !== '' ? $src : null;
        }
        return null;
    }

    private function extractString(Crawler $crawler, FieldDefinition $field): ?string
    {
        $name = strtolower($field->name);

        if (in_array($name, ['image', 'img', 'picture'], true)) {
            $img = $this->safeFilter($crawler, 'img');
            if ($img->count() > 0) {
                $src = $img->first()->attr('src');
                return $src !== null && $src !== '' ? $src : null;
            }
            return null;
        }

        if (in_array($name, ['link', 'url', 'href'], true)) {
            $a = $this->safeFilter($crawler, 'a[href]');
            if ($a->count() > 0) {
                $href = $a->first()->attr('href');
                return $href !== null && $href !== '' ? $href : null;
            }
            return null;
        }

        foreach ($this->selectorsFor($field) as $selector) {
            $match = $this->safeFilter($crawler, $selector);
            if ($match->count() > 0) {
                return $this->normaliseText($match->first()->text());
            }
        }
        return null;
    }

    private function extractHtml(Crawler $crawler, FieldDefinition $field): ?string
    {
        foreach ($this->selectorsFor($field) as $selector) {
            $match = $this->safeFilter($crawler, $selector);
            if ($match->count() > 0) {
                $html = $match->first()->html();
                $trimmed = trim($html);
                return $trimmed === '' ? null : $trimmed;
            }
        }
        return null;
    }

    private function extractInt(Crawler $crawler, FieldDefinition $field): ?string
    {
        foreach ($this->selectorsFor($field) as $selector) {
            $match = $this->safeFilter($crawler, $selector);
            if ($match->count() > 0) {
                if (preg_match('/-?\d+/', $match->first()->text(), $m) === 1) {
                    return $m[0];
                }
            }
        }
        // Fall back to scanning the whole block text.
        $allText = $this->safeText($crawler);
        if ($allText !== null && preg_match('/-?\d+/', $allText, $m) === 1) {
            return $m[0];
        }
        return null;
    }

    private function extractDate(Crawler $crawler, FieldDefinition $field): ?string
    {
        // Prefer <time datetime="..."> when the document is HTML5-correct.
        $time = $this->safeFilter($crawler, 'time[datetime]');
        if ($time->count() > 0) {
            $dt = $time->first()->attr('datetime');
            $iso = $this->parseDate($dt);
            if ($iso !== null) {
                return $iso;
            }
        }

        foreach ($this->selectorsFor($field) as $selector) {
            $match = $this->safeFilter($crawler, $selector);
            if ($match->count() > 0) {
                $iso = $this->parseDate($match->first()->text());
                if ($iso !== null) {
                    return $iso;
                }
            }
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private function selectorsFor(FieldDefinition $field): array
    {
        $name = $field->name;
        $byHeuristic = match (strtolower($name)) {
            'header', 'title', 'headline' => ['h1', 'h2', 'h3'],
            'subheader', 'subtitle' => ['h2', 'h3', 'h4'],
            'bodytext', 'body', 'text' => ['p'],
            'image', 'img', 'picture' => ['img'],
            'link', 'url', 'href' => ['a[href]'],
            default => [],
        };

        return array_merge(
            $byHeuristic,
            [
                sprintf('.%s', $name),
                sprintf('[data-field="%s"]', $name),
            ],
        );
    }

    private function safeFilter(Crawler $crawler, string $selector): Crawler
    {
        try {
            return $crawler->filter($selector);
        } catch (Throwable) {
            return new Crawler();
        }
    }

    private function safeText(Crawler $crawler): ?string
    {
        try {
            $text = $crawler->text();
            return $text === '' ? null : $text;
        } catch (Throwable) {
            return null;
        }
    }

    private function normaliseText(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return trim($value);
    }

    private function parseDate(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d', $ts);
    }
}
