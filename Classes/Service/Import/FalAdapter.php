<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use RuntimeException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Production FAL wrapper. Only usable inside a booted TYPO3 site.
 *
 * @todo Functional test under typo3/testing-framework once the import command
 *       wiring (#16) lands and we have FAL fixtures.
 */
final class FalAdapter implements FalAdapterInterface
{
    public function findUidBySha1(string $sha1): ?int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_file');

        $uid = $queryBuilder
            ->select('uid')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq(
                    'sha1',
                    $queryBuilder->createNamedParameter($sha1),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $uid === false || $uid === null ? null : (int)$uid;
    }

    public function addFile(string $sourcePath, int $storageUid, string $folderPath): int
    {
        $storage = GeneralUtility::makeInstance(StorageRepository::class)
            ->getStorageObject($storageUid);

        $folderPath = $folderPath === '' ? '/' : $folderPath;
        try {
            $folder = $storage->getFolder($folderPath);
        } catch (FolderDoesNotExistException) {
            $folder = $storage->createFolder($folderPath);
        }

        // removeOriginal: false — the source typically lives in a static HTML
        // scrape directory the importer must not mutate.
        $file = $storage->addFile($sourcePath, $folder, '', DuplicationBehavior::RENAME, false);
        return $file->getUid();
    }

    public function updateMetadata(int $fileUid, array $metadata): void
    {
        if ($metadata === []) {
            return;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata');

        $existing = $connection
            ->select(['uid'], 'sys_file_metadata', ['file' => $fileUid])
            ->fetchAssociative();

        if ($existing === false) {
            $connection->insert('sys_file_metadata', ['file' => $fileUid] + $metadata);
            return;
        }

        if (!isset($existing['uid'])) {
            throw new RuntimeException(sprintf('sys_file_metadata row for file %d is missing uid', $fileUid));
        }
        $connection->update('sys_file_metadata', $metadata, ['uid' => (int)$existing['uid']]);
    }
}
