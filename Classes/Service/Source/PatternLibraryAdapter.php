<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Source;

use DirectoryIterator;
use InvalidArgumentException;
use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;

/**
 * Reads a directory tree of component patterns.
 *
 * Layout convention (Fractal-flavoured, generic enough for most pattern
 * libraries):
 *
 *   patterns/
 *     card/index.html              -> component=card, variant=null
 *     card/featured/index.html     -> component=card, variant=featured
 *     hero/index.html              -> component=hero, variant=null
 *
 * Components are top-level subdirs; variants are one level deeper. Anything
 * deeper is ignored (open a follow-up if you need recursive nesting).
 *
 * Symlinks are skipped, file size is capped (10 MB default) and `realpath()`
 * containment is double-checked, mirroring `LocalFilesAdapter`.
 *
 * @todo Storybook `stories.json` / `index.json` parsing and Fractal manifest
 *       reading are tracked as follow-ups; this adapter is the lowest common
 *       denominator that works without tool-specific harnesses.
 */
final class PatternLibraryAdapter implements SourceAdapterInterface
{
    public const DEFAULT_MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly int $maxFileSize = self::DEFAULT_MAX_FILE_SIZE,
        private readonly string $indexFilename = 'index.html',
    ) {
    }

    /**
     * @return iterable<SourceDocument>
     */
    public function read(string $source): iterable
    {
        $root = $this->validateDirectory($source);
        $rootSep = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        foreach ($this->safeIterate($root) as $componentEntry) {
            if (!$this->isUsableSubdir($componentEntry)) {
                continue;
            }
            $componentName = $componentEntry->getFilename();
            $componentDir = $componentEntry->getPathname();

            $directIndex = $componentDir . DIRECTORY_SEPARATOR . $this->indexFilename;
            $doc = $this->buildDocument($directIndex, $rootSep, $componentName, null);
            if ($doc !== null) {
                yield $doc;
            }

            foreach ($this->safeIterate($componentDir) as $variantEntry) {
                if (!$this->isUsableSubdir($variantEntry)) {
                    continue;
                }
                $variantName = $variantEntry->getFilename();
                $variantIndex = $variantEntry->getPathname() . DIRECTORY_SEPARATOR . $this->indexFilename;
                $doc = $this->buildDocument($variantIndex, $rootSep, $componentName, $variantName);
                if ($doc !== null) {
                    yield $doc;
                }
            }
        }
    }

    /**
     * @return iterable<DirectoryIterator>
     */
    private function safeIterate(string $path): iterable
    {
        if (!is_dir($path) || !is_readable($path)) {
            return;
        }
        foreach (new DirectoryIterator($path) as $entry) {
            yield $entry;
        }
    }

    private function isUsableSubdir(DirectoryIterator $entry): bool
    {
        if ($entry->isDot() || !$entry->isDir() || $entry->isLink()) {
            return false;
        }
        $name = $entry->getFilename();
        return $name !== '' && !str_starts_with($name, '.');
    }

    private function buildDocument(string $absolute, string $rootSep, string $component, ?string $variant): ?SourceDocument
    {
        if (!is_file($absolute) || is_link($absolute) || !is_readable($absolute)) {
            return null;
        }
        $size = filesize($absolute);
        if ($size === false || $size > $this->maxFileSize) {
            return null;
        }
        $real = realpath($absolute);
        if ($real === false || !str_starts_with($real, $rootSep)) {
            return null;
        }
        $html = @file_get_contents($real, length: $this->maxFileSize);
        if ($html === false) {
            throw new RuntimeException(sprintf('Cannot read pattern: %s', $real));
        }

        return new SourceDocument(
            path: substr($real, strlen($rootSep)),
            html: $html,
            metadata: [
                'absolute_path' => $real,
                'component' => $component,
                'variant' => $variant,
                'size' => $size,
            ],
        );
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
}
