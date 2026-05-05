<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Production wrapper around TYPO3's DataHandler. Only usable inside a booted
 * TYPO3 site (DataHandler relies on $GLOBALS state and a backend user context).
 * Tests inject a recording fake; production wiring resolves to this class.
 *
 * tt_content rows are written via DataHandler so TCA validation, hooks and
 * reference index updates run. sys_file_reference rows are written directly
 * via ConnectionPool: DataHandler's `type=file` IRRE pipeline rejects NEW-key
 * children before the remap stack can resolve them (FileExtensionFilter calls
 * ResourceFactory::getFileReferenceObject() with the unresolved NEW key, fails
 * with ResourceDoesNotExistException, and silently drops the relation).
 */
final class DataHandlerAdapter implements DataHandlerAdapterInterface
{
    public function processContent(int $pid, array $payload, ?int $existingUid, array $fileReferences = []): int
    {
        $contentKey = $existingUid ?? StringUtility::getUniqueId('NEWshi_c');
        $contentRow = ['pid' => $pid] + $payload;
        $data = ['tt_content' => [$contentKey => $contentRow]];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new RuntimeException('DataHandler errors: ' . implode('; ', $dataHandler->errorLog));
        }

        if ($existingUid !== null) {
            $contentUid = $existingUid;
        } else {
            $resolved = $dataHandler->substNEWwithIDs[$contentKey] ?? null;
            if ($resolved === null) {
                throw new RuntimeException('DataHandler did not return a uid for the new tt_content record');
            }
            $contentUid = (int)$resolved;
        }

        if ($fileReferences !== []) {
            $this->writeFileReferences($pid, $contentUid, $fileReferences);
        }

        return $contentUid;
    }

    public function findByBlockId(string $blockId): ?int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        $uid = $queryBuilder
            ->select('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'tx_static_html_importer_block_id',
                    $queryBuilder->createNamedParameter($blockId),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false || $uid === null ? null : (int)$uid;
    }

    /**
     * @param array<string, list<int>> $fileReferences
     */
    private function writeFileReferences(int $pid, int $contentUid, array $fileReferences): void
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $referenceConn = $connectionPool->getConnectionForTable('sys_file_reference');
        $contentConn = $connectionPool->getConnectionForTable('tt_content');

        foreach ($fileReferences as $fieldname => $fileUids) {
            if (!is_array($fileUids) || $fileUids === []) {
                continue;
            }
            // Idempotent re-runs: drop the previous references for this field
            // before re-creating them so the count below matches reality.
            $referenceConn->delete('sys_file_reference', [
                'tablenames' => 'tt_content',
                'uid_foreign' => $contentUid,
                'fieldname' => (string)$fieldname,
            ]);
            $sorting = 0;
            foreach ($fileUids as $fileUid) {
                $sorting++;
                $referenceConn->insert('sys_file_reference', [
                    'pid' => $pid,
                    'uid_local' => (int)$fileUid,
                    'tablenames' => 'tt_content',
                    'uid_foreign' => $contentUid,
                    'fieldname' => (string)$fieldname,
                    'sorting_foreign' => $sorting,
                ]);
            }
            $contentConn->update(
                'tt_content',
                [(string)$fieldname => count($fileUids)],
                ['uid' => $contentUid],
            );
        }
    }
}
