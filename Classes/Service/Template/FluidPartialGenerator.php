<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Service\Template;

use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;

/**
 * Distils analysed ContentBlocks into Fluid partials, per-cType templates, and
 * a default layout under a target root (typically `EXT:.../Resources/Private/`).
 *
 * Strategy: blocks group by their structural hash (`ContentBlock::$id` from
 * BlockHasher) so identical structures collapse to a single partial. Each
 * primary candidate type produces a template that composes its partials.
 * A `.manifest.json` under `Partials/Generated/` records what the generator
 * wrote at which content hash so re-runs are idempotent.
 *
 * Field-level placeholder extraction (replacing block text with `{block.title}`
 * etc.) is intentionally out of scope here; FieldTransformer (issue #12) drives
 * that. The partial body for now is the original block HTML, wrapped in a
 * Fluid comment that names the source structure.
 */
final class FluidPartialGenerator implements FluidPartialGeneratorInterface
{
    private const MANIFEST_NAME = '.manifest.json';
    private const PARTIAL_HASH_PREFIX_LEN = 12;

    /**
     * @param  list<ContentBlock> $blocks
     * @return list<string>       paths of files (would-be-)written, relative to $targetRoot
     */
    public function generate(array $blocks, string $targetRoot, bool $dryRun = false): array
    {
        $root = $this->validateTargetRoot($targetRoot);

        $manifestPath = $root . '/Partials/Generated/' . self::MANIFEST_NAME;
        $existingManifest = $this->loadManifest($manifestPath);
        $newManifest = [];
        $written = [];

        $this->writeLayoutIfMissing($root, $written, $newManifest, $dryRun);

        $byHash = $this->groupByHash($blocks);
        foreach ($byHash as $hash => $hashedBlocks) {
            $relativePath = sprintf(
                'Partials/Generated/%s.html',
                substr($hash, 0, self::PARTIAL_HASH_PREFIX_LEN),
            );
            $content = $this->renderPartial($hash, $hashedBlocks);
            $this->writeIfChanged($root, $relativePath, $content, $existingManifest, $newManifest, $written, $dryRun);
        }

        $byType = $this->groupByPrimaryType($blocks);
        foreach ($byType as $cType => $hashSet) {
            $relativePath = sprintf('Templates/%s.html', $this->sanitizeCType($cType));
            $content = $this->renderTemplate($cType, array_keys($hashSet));
            $this->writeIfChanged($root, $relativePath, $content, $existingManifest, $newManifest, $written, $dryRun);
        }

        if (!$dryRun) {
            $this->writeManifest($manifestPath, $newManifest);
        }

        return $written;
    }

    /**
     * @param  list<ContentBlock> $blocks
     * @return array<string, list<ContentBlock>>
     */
    private function groupByHash(array $blocks): array
    {
        $byHash = [];
        foreach ($blocks as $block) {
            $byHash[$block->id] ??= [];
            $byHash[$block->id][] = $block;
        }
        return $byHash;
    }

    /**
     * @param  list<ContentBlock> $blocks
     * @return array<string, array<string, true>>  cType => set of hashes
     */
    private function groupByPrimaryType(array $blocks): array
    {
        $byType = [];
        foreach ($blocks as $block) {
            $type = $block->candidateTypes[0] ?? 'unknown';
            $byType[$type] ??= [];
            $byType[$type][$block->id] = true;
        }
        return $byType;
    }

    /**
     * @param array<string, string>      $existingManifest
     * @param array<string, string>      $newManifest      receives the entry by reference
     * @param list<string>               $written          receives the entry by reference
     */
    private function writeIfChanged(
        string $root,
        string $relativePath,
        string $content,
        array $existingManifest,
        array &$newManifest,
        array &$written,
        bool $dryRun = false,
    ): void {
        $absolute = $root . '/' . $relativePath;
        $contentHash = sha1($content);

        // Skip iff (a) we'd generate the same content as last time AND (b) the
        // file on disk is still that exact content. A hand-edit invalidates (b)
        // and triggers a regeneration so the target stays in sync with the
        // manifest. Hand-edits are not preserved; persistent customisations
        // belong outside `Generated/`.
        if (
            ($existingManifest[$relativePath] ?? null) === $contentHash
            && is_file($absolute)
            && @sha1_file($absolute) === $contentHash
        ) {
            $newManifest[$relativePath] = $contentHash;
            return;
        }

        if (!$dryRun) {
            $this->writeFile($absolute, $content);
        }
        $newManifest[$relativePath] = $contentHash;
        $written[] = $relativePath;
    }

    /**
     * @param array<string, string> $newManifest receives entry by reference
     * @param list<string>          $written     receives entry by reference
     */
    private function writeLayoutIfMissing(string $root, array &$written, array &$newManifest, bool $dryRun): void
    {
        $relative = 'Layouts/Default.html';
        $absolute = $root . '/' . $relative;
        $content = $this->renderLayout();
        if (!is_file($absolute)) {
            if (!$dryRun) {
                $this->writeFile($absolute, $content);
            }
            $written[] = $relative;
        }
        $newManifest[$relative] = sha1($content);
    }

    private function renderLayout(): string
    {
        return <<<'FLUID'
{namespace f=TYPO3\CMS\Fluid\ViewHelpers}
<f:render section="Main" />

FLUID;
    }

    /**
     * @param list<ContentBlock> $blocks
     */
    private function renderPartial(string $hash, array $blocks): string
    {
        $sample = $blocks[0];
        $candidates = $sample->candidateTypes === [] ? '(none)' : implode(', ', $sample->candidateTypes);
        $count = count($blocks);

        return sprintf(
            "{namespace f=TYPO3\\CMS\\Fluid\\ViewHelpers}\n<f:comment>\nAuto-generated partial. Structure hash: %s. Matched %d block(s). Candidate types: %s.\nField placeholders are not yet inserted; refine after FieldTransformer (#12) lands.\n</f:comment>\n%s\n",
            $hash,
            $count,
            $candidates,
            $sample->html,
        );
    }

    /**
     * @param list<string> $hashes
     */
    private function renderTemplate(string $cType, array $hashes): string
    {
        sort($hashes);
        $renders = '';
        foreach ($hashes as $hash) {
            $renders .= sprintf(
                "<f:render partial=\"Generated/%s\" arguments=\"{_all}\" />\n",
                substr($hash, 0, self::PARTIAL_HASH_PREFIX_LEN),
            );
        }

        return sprintf(
            "{namespace f=TYPO3\\CMS\\Fluid\\ViewHelpers}\n<f:layout name=\"Default\" />\n<f:section name=\"Main\">\n<!-- %d partial(s) for cType \"%s\" -->\n%s</f:section>\n",
            count($hashes),
            $cType,
            $renders,
        );
    }

    /**
     * Maps an arbitrary cType label onto a safe filename component. Heuristic
     * cTypes like `role:contentinfo` or `bem:card` contain colons; we replace
     * anything outside `[A-Za-z0-9_-]` with `_`.
     */
    private function sanitizeCType(string $cType): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', $cType);
        return $clean === null || $clean === '' ? 'unknown' : $clean;
    }

    private function validateTargetRoot(string $targetRoot): string
    {
        if (trim($targetRoot) === '' || str_contains($targetRoot, "\0")) {
            throw new RuntimeException('targetRoot must not be empty or contain null bytes');
        }
        $real = realpath($targetRoot);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException(sprintf('targetRoot does not exist or is not a directory: %s', $targetRoot));
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, string>
     */
    private function loadManifest(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $clean = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $clean[$k] = $v;
            }
        }
        return $clean;
    }

    /**
     * @param array<string, string> $manifest
     */
    private function writeManifest(string $path, array $manifest): void
    {
        ksort($manifest);
        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Cannot encode manifest as JSON');
        }
        $this->writeFile($path, $encoded . "\n");
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Cannot create directory: %s', $dir));
        }
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Cannot write file: %s', $path));
        }
    }
}
