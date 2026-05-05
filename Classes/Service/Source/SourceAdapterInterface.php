<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;

/**
 * Adapter that maps a heterogeneous source onto a stream of SourceDocuments.
 *
 * `$source` is opaque to callers; each adapter validates and interprets it
 * (filesystem path, URL, pattern library id, ...).
 */
interface SourceAdapterInterface
{
    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable;
}
