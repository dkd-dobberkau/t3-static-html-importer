<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Analyzer;

use DOMElement;
use DOMNode;

/**
 * Hashes a DOM subtree on structure only, ignoring text content.
 *
 * Includes tag name, sorted class tokens, `data-component` and `role`. Skips
 * `id` (always unique) and any free attributes so two visually different but
 * structurally identical cards collapse to the same hash.
 *
 * Recursion depth and per-node child count are capped so pathological inputs
 * (e.g. a thousands-deep div tree, or a page generated to brute-force the
 * hasher) cannot exhaust memory.
 */
final class BlockHasher
{
    private const MAX_DEPTH = 32;
    private const MAX_CHILDREN_PER_NODE = 500;

    public function hash(DOMNode $node): string
    {
        return sha1($this->signature($node, 0));
    }

    private function signature(DOMNode $node, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return '#';
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        /** @var DOMElement $node */
        $bits = [strtolower($node->tagName)];

        $class = trim($node->getAttribute('class'));
        if ($class !== '') {
            $tokens = array_unique(preg_split('/\s+/', $class) ?: []);
            sort($tokens);
            $bits[] = '.' . implode('.', $tokens);
        }
        foreach (['data-component', 'role'] as $name) {
            if ($node->hasAttribute($name)) {
                $bits[] = $name . '=' . $node->getAttribute($name);
            }
        }

        $children = [];
        $count = 0;
        foreach ($node->childNodes as $child) {
            if ($count >= self::MAX_CHILDREN_PER_NODE) {
                $children[] = '+';
                break;
            }
            $sig = $this->signature($child, $depth + 1);
            if ($sig !== '') {
                $children[] = $sig;
                $count++;
            }
        }
        if ($children !== []) {
            $bits[] = '(' . implode(',', $children) . ')';
        }
        return implode('|', $bits);
    }
}
