<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

/**
 * Thin abstraction over TYPO3's FAL (`ResourceFactory`, `ResourceStorage`,
 * `sys_file` query builder). Lets `FalImporter` stay unit-testable without
 * booting TYPO3.
 */
interface FalAdapterInterface
{
    /**
     * Returns an existing sys_file uid for the given content hash, or null.
     */
    public function findUidBySha1(string $sha1): ?int;

    /**
     * Adds a file to the configured storage and folder. Returns sys_file uid.
     */
    public function addFile(string $sourcePath, int $storageUid, string $folderPath): int;

    /**
     * Updates sys_file_metadata for the given file uid.
     *
     * @param array<string, mixed> $metadata column => value (e.g. alternative, title, description)
     */
    public function updateMetadata(int $fileUid, array $metadata): void;
}
