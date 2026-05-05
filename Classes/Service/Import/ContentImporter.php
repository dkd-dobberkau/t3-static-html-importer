<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;
use T3x\StaticHtmlImporter\Service\Mapping\FieldTransformerInterface;

/**
 * Persists one tt_content record per analysed ContentBlock.
 *
 * Builds a column => value payload by running each FieldDefinition through the
 * FieldTransformer, then routes the write to a DataHandlerAdapter. Idempotency
 * is dedupe-by-block-id: the column `tx_static_html_importer_block_id` carries
 * the BlockHasher hash so a re-run updates the existing record instead of
 * inserting a duplicate.
 *
 * Dry-run support and CSV review reports live in ImportCommand (issue #16),
 * which can call `buildPayload()` directly without invoking the adapter.
 */
final class ContentImporter implements ContentImporterInterface
{
    public const DEDUPE_COLUMN = 'tx_static_html_importer_block_id';

    public function __construct(
        private readonly FieldTransformerInterface $fieldTransformer,
        private readonly DataHandlerAdapterInterface $adapter,
    ) {
    }

    public function import(ContentBlock $block, ImportMapping $mapping, int $targetPid): int
    {
        $payload = $this->buildPayload($block, $mapping);
        $existingUid = $this->adapter->findByBlockId($block->id);
        return $this->adapter->processContent($targetPid, $payload, $existingUid);
    }

    /**
     * Public so ImportCommand can preview a dry-run without touching the DB.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(ContentBlock $block, ImportMapping $mapping): array
    {
        $payload = [
            'CType' => $mapping->cType,
            self::DEDUPE_COLUMN => $block->id,
        ];

        foreach ($mapping->fields as $columnName => $field) {
            $value = $this->fieldTransformer->transform($block, $mapping, $field);
            if ($value !== null) {
                $payload[$columnName] = $value;
            }
        }

        return $payload;
    }
}
