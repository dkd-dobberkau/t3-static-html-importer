<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Ai;

use InvalidArgumentException;
use RuntimeException;

/**
 * Disk-backed cache for LLM results.
 *
 * Keys are SHA1-hashed and sharded across two-character subdirectories so a
 * cache directory does not blow up with thousands of files at the top level.
 * Values are stored as pretty-printed JSON to make manual inspection easy.
 *
 * Files are written 0600 and parent directories 0700 so the cache (which can
 * contain LLM output echoing back parts of the input HTML) is not world- or
 * group-readable. Pass `--no-cache` at the call site for sensitive runs once
 * a flag is wired up.
 *
 * @todo The default path is relative to the CWD so the skeleton runs without
 *       TYPO3 boot. Wire it through `Environment::getVarPath()` in #7.
 */
final class ResultCache
{
    private readonly string $cacheDir;

    public function __construct(
        string $cacheDir = 'var/cache/t3_static_html_importer',
    ) {
        $cacheDir = trim($cacheDir);
        if ($cacheDir === '' || str_contains($cacheDir, "\0")) {
            throw new InvalidArgumentException('cacheDir must not be empty or contain null bytes');
        }
        $segments = explode('/', str_replace('\\', '/', $cacheDir));
        if (in_array('..', $segments, true)) {
            throw new InvalidArgumentException(sprintf('cacheDir must not contain ".." segments: %s', $cacheDir));
        }

        if (!str_starts_with($cacheDir, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $cacheDir) !== 1) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new RuntimeException('Cannot resolve working directory for relative cacheDir');
            }
            $cacheDir = $cwd . DIRECTORY_SEPARATOR . $cacheDir;
        }

        $this->cacheDir = rtrim($cacheDir, '/' . DIRECTORY_SEPARATOR);
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
        if (!is_dir($dir) && !mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create cache directory: %s', $dir));
        }
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Cannot encode cache value as JSON');
        }
        if (file_put_contents($file, $encoded) === false) {
            throw new RuntimeException(sprintf('Cannot write cache file: %s', $file));
        }
        @chmod($file, 0o600);
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
            $this->cacheDir,
            substr($hash, 0, 2),
            $hash,
        );
    }
}
