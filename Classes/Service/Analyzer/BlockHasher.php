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
 */
final class BlockHasher
{
    public function hash(DOMNode $node): string
    {
        return sha1($this->signature($node));
    }

    private function signature(DOMNode $node): string
    {
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
        foreach ($node->childNodes as $child) {
            $sig = $this->signature($child);
            if ($sig !== '') {
                $children[] = $sig;
            }
        }
        if ($children !== []) {
            $bits[] = '(' . implode(',', $children) . ')';
        }
        return implode('|', $bits);
    }
}
