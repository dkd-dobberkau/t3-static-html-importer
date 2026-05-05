<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Import;

use RuntimeException;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierInterface;
use Throwable;

/**
 * Imports binary assets into TYPO3's FAL with SHA1 content dedupe.
 *
 * Strategy: hash the source file's contents; if a sys_file row already exists
 * with the same sha1, return that uid (no copy, no re-upload). Otherwise add
 * the file to the configured storage and folder, then optionally enrich
 * sys_file_metadata via AiClassifierInterface::enrichAssetMetadata.
 *
 * Source paths are constrained to `$sourceBaseDir` if set, so an attacker who
 * planted an `<img src="/etc/passwd">` cannot trick the importer into
 * uploading arbitrary local files. Without `$sourceBaseDir` configured, the
 * caller is responsible for vetting paths.
 */
final class FalImporter implements FalImporterInterface
{
    public const DEFAULT_STORAGE_UID = 1;

    public function __construct(
        private readonly FalAdapterInterface $adapter,
        private readonly AiClassifierInterface $ai,
        private readonly int $defaultStorageUid = self::DEFAULT_STORAGE_UID,
        private readonly bool $enrichMetadata = false,
        private readonly ?string $sourceBaseDir = null,
    ) {
    }

    public function importFile(string $sourcePath, string $targetFolder): int
    {
        $resolved = $this->resolveSource($sourcePath);

        $sha1 = sha1_file($resolved);
        if ($sha1 === false) {
            throw new RuntimeException(sprintf('Cannot hash file: %s', $resolved));
        }

        $existingUid = $this->adapter->findUidBySha1($sha1);
        if ($existingUid !== null) {
            return $existingUid;
        }

        [$storageUid, $folderPath] = $this->parseTarget($targetFolder);
        $uid = $this->adapter->addFile($resolved, $storageUid, $folderPath);

        if ($this->enrichMetadata) {
            $this->enrichBestEffort($uid, $resolved);
        }

        return $uid;
    }

    private function enrichBestEffort(int $fileUid, string $imagePath): void
    {
        try {
            $meta = $this->ai->enrichAssetMetadata($imagePath);
            $this->adapter->updateMetadata($fileUid, [
                'alternative' => $meta->altText,
                'title' => $meta->title,
                'description' => $meta->description,
            ]);
        } catch (Throwable) {
            // Metadata enrichment is best-effort: an LLM hiccup must not abort
            // the import. The file is already in FAL.
        }
    }

    private function resolveSource(string $sourcePath): string
    {
        $real = realpath($sourcePath);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            throw new RuntimeException(sprintf('Source file is not readable: %s', $sourcePath));
        }

        if ($this->sourceBaseDir !== null) {
            $base = realpath($this->sourceBaseDir);
            if ($base === false || !is_dir($base)) {
                throw new RuntimeException(sprintf('sourceBaseDir does not exist: %s', $this->sourceBaseDir));
            }
            $baseSep = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (!str_starts_with($real, $baseSep)) {
                throw new RuntimeException(sprintf('Source %s is outside sourceBaseDir %s', $real, $base));
            }
        }

        return $real;
    }

    /**
     * Accepts FAL identifier syntax `1:/path/to` or a bare path that uses the
     * default storage uid.
     *
     * @return array{0: int, 1: string}
     */
    private function parseTarget(string $targetFolder): array
    {
        $targetFolder = trim($targetFolder);
        if (preg_match('/^(\d+):(.*)$/', $targetFolder, $m) === 1) {
            $folder = $m[2] === '' ? '/' : $m[2];
            return [(int)$m[1], $folder];
        }
        $folder = $targetFolder === '' ? '/' : $targetFolder;
        return [$this->defaultStorageUid, $folder];
    }
}
