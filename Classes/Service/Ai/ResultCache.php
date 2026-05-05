<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use RuntimeException;

/**
 * Disk-backed cache for LLM results.
 *
 * Keys are SHA1-hashed and sharded across two-character subdirectories so a
 * cache directory does not blow up with thousands of files at the top level.
 * Values are stored as pretty-printed JSON to make manual inspection easy.
 *
 * @todo The default path is relative to the CWD so the skeleton runs without
 *       TYPO3 boot. Wire it through `Environment::getVarPath()` in #7.
 */
final class ResultCache
{
    public function __construct(
        private readonly string $cacheDir = 'var/cache/t3_static_html_importer',
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $file = $this->path($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function set(string $key, array $value): void
    {
        $file = $this->path($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create cache directory: %s', $dir));
        }
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Cannot encode cache value as JSON');
        }
        if (file_put_contents($file, $encoded) === false) {
            throw new RuntimeException(sprintf('Cannot write cache file: %s', $file));
        }
    }

    public function key(string ...$parts): string
    {
        return sha1(implode("\x1f", $parts));
    }

    private function path(string $key): string
    {
        $hash = sha1($key);
        return sprintf(
            '%s/%s/%s.json',
            rtrim($this->cacheDir, '/'),
            substr($hash, 0, 2),
            $hash,
        );
    }
}
