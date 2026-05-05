<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Production wrapper around TYPO3's DataHandler.
 *
 * Only usable inside a booted TYPO3 site (DataHandler relies on $GLOBALS state
 * and a backend user context). Tests inject a fake DataHandlerAdapterInterface
 * so this class is intentionally not unit-tested.
 *
 * @todo Functional test under typo3/testing-framework once the test fixtures
 *       harness is in place.
 */
final class DataHandlerAdapter implements DataHandlerAdapterInterface
{
    public function processContent(int $pid, array $payload, ?int $existingUid, array $fileReferences = []): int
    {
        $contentKey = $existingUid ?? 'NEW' . uniqid('shi_c', true);
        $contentRow = ['pid' => $pid] + $payload;
        $data = ['tt_content' => [$contentKey => $contentRow]];

        foreach ($fileReferences as $fieldname => $fileUids) {
            if (!is_array($fileUids) || $fileUids === []) {
                continue;
            }
            $refKeys = [];
            foreach ($fileUids as $fileUid) {
                $refKey = 'NEW' . uniqid('shi_r', true);
                $refKeys[] = $refKey;
                $data['sys_file_reference'][$refKey] = [
                    'pid' => $pid,
                    'table_local' => 'sys_file',
                    'uid_local' => (int)$fileUid,
                    'tablenames' => 'tt_content',
                    'uid_foreign' => $contentKey,
                    'fieldname' => (string)$fieldname,
                ];
            }
            $data['tt_content'][$contentKey][$fieldname] = implode(',', $refKeys);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new RuntimeException('DataHandler errors: ' . implode('; ', $dataHandler->errorLog));
        }

        if ($existingUid !== null) {
            return $existingUid;
        }

        $uid = $dataHandler->substNEWwithIDs[$contentKey] ?? null;
        if ($uid === null) {
            throw new RuntimeException('DataHandler did not return a uid for the new tt_content record');
        }
        return (int)$uid;
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
}
