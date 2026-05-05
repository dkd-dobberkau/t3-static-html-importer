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
 * Filters .html and .htm (case-insensitive). Skips dotfiles. Each
 * SourceDocument carries a path relative to the source root as its logical
 * identifier so downstream stages can dedupe and reference back.
 */
final class LocalFilesAdapter implements SourceAdapterInterface
{
    private const HTML_EXTENSIONS = ['html', 'htm'];

    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable
    {
        $root = $this->validateDirectory($source);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !$this->isHtml($file->getFilename())) {
                continue;
            }

            $absolute = $file->getPathname();
            $html = @file_get_contents($absolute);
            if ($html === false) {
                throw new RuntimeException(sprintf('Cannot read file: %s', $absolute));
            }

            yield new SourceDocument(
                path: $this->relativePath($root, $absolute),
                html: $html,
                metadata: [
                    'absolute_path' => $absolute,
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

    private function relativePath(string $root, string $absolute): string
    {
        $rootWithSep = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($absolute, $rootWithSep)) {
            return substr($absolute, strlen($rootWithSep));
        }
        return $absolute;
    }
}
