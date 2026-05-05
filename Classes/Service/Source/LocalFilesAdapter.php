<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;

/**
 * Reads HTML files recursively from a local directory.
 *
 * Filters .html and .htm (case-insensitive). Skips dotfiles, symlinks (a
 * `.html` symlink to `/etc/passwd` would otherwise be read and shipped into
 * the LLM prompt), and files larger than `$maxFileSize` (default 10 MB).
 * Each yielded SourceDocument carries a path relative to the source root as
 * its logical identifier so downstream stages can dedupe and reference back.
 *
 * Containment is double-checked via `realpath()` so even hard links pointing
 * outside the source tree are skipped.
 */
final class LocalFilesAdapter implements SourceAdapterInterface
{
    public const DEFAULT_MAX_FILE_SIZE = 10 * 1024 * 1024;

    private const HTML_EXTENSIONS = ['html', 'htm'];

    public function __construct(
        private readonly int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE,
    ) {
    }

    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable
    {
        $root = $this->validateDirectory($source);
        $rootSep = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$this->isHtml($file->getFilename())) {
                continue;
            }
            if ($file->isLink()) {
                continue;
            }
            if ($file->getSize() > $this->maxFileSize) {
                continue;
            }

            $real = realpath($file->getPathname());
            if ($real === false || !str_starts_with($real, $rootSep)) {
                continue;
            }

            $html = @file_get_contents($real, length: $this->maxFileSize);
            if ($html === false) {
                throw new RuntimeException(sprintf('Cannot read file: %s', $real));
            }

            yield new SourceDocument(
                path: substr($real, strlen($rootSep)),
                html: $html,
                metadata: [
                    'absolute_path' => $real,
                    'mtime' => $file->getMTime(),
                    'size' => $file->getSize(),
                ],
            );
        }
    }

    private function validateDirectory(string $source): string
    {
        $real = realpath($source);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException(sprintf('Source is not a directory: %s', $source));
        }
        if (!is_readable($real)) {
            throw new InvalidArgumentException(sprintf('Source is not readable: %s', $source));
        }
        return $real;
    }

    private function isHtml(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::HTML_EXTENSIONS, true);
    }
}
