<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

/**
 * Thin abstraction over TYPO3's `DataHandler` so the importer stays unit-testable
 * without booting TYPO3. Production wiring resolves to `DataHandlerAdapter`,
 * which calls `DataHandler::process_datamap()` and the standard
 * `ConnectionPool` query builder; tests inject a recording fake.
 */
interface DataHandlerAdapterInterface
{
    /**
     * Inserts a new tt_content row when `$existingUid` is null, updates it
     * otherwise. Optional `$fileReferences` create matching `sys_file_reference`
     * rows in the same DataHandler transaction so image/asset columns end up
     * with proper FAL relations rather than raw uids.
     *
     * @param array<string, mixed>          $payload         column => value, must include CType
     * @param array<string, list<int>>      $fileReferences  column => list of sys_file uids
     */
    public function processContent(int $pid, array $payload, ?int $existingUid, array $fileReferences = []): int;

    /**
     * Looks up an existing tt_content uid by the dedupe column
     * `tx_static_html_importer_block_id`.
     */
    public function findByBlockId(string $blockId): ?int;
}
