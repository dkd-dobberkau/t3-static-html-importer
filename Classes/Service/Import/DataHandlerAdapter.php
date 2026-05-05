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
 *       harness is in place (ImportCommand wiring in #16 is the natural point).
 */
final class DataHandlerAdapter implements DataHandlerAdapterInterface
{
    public function processContent(int $pid, array $payload, ?int $existingUid): int
    {
        $key = $existingUid ?? 'NEW' . uniqid('shi', true);
        $row = ['pid' => $pid] + $payload;
        $data = ['tt_content' => [$key => $row]];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            throw new RuntimeException('DataHandler errors: ' . implode('; ', $dataHandler->errorLog));
        }

        if ($existingUid !== null) {
            return $existingUid;
        }

        $uid = $dataHandler->substNEWwithIDs[$key] ?? null;
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
