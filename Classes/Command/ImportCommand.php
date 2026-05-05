<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;
use T3x\StaticHtmlImporter\Service\Analyzer\StructuralAnalyzer;
use T3x\StaticHtmlImporter\Service\Import\ContentImporter;
use T3x\StaticHtmlImporter\Service\Import\DataHandlerAdapterInterface;
use T3x\StaticHtmlImporter\Service\Import\FalImporterInterface;
use T3x\StaticHtmlImporter\Service\Mapping\YamlMappingLoader;
use T3x\StaticHtmlImporter\Service\Source\LocalFilesAdapter;
use Throwable;

/**
 * Persists analysed blocks as tt_content records and imports referenced
 * binary assets into FAL.
 *
 * Pipeline per block: pick the matching mapping by `block->candidateTypes`
 * priority (the first entry that has a YAML mapping wins) -> build the
 * tt_content payload via ContentImporter -> route image-type fields through
 * FalImporter and collect their uids as `sys_file_reference` rows -> persist
 * via DataHandlerAdapter in a single transaction so FAL relations are
 * properly created. Non-fatal errors collect into a CSV review report; the
 * import continues so partial progress is never lost.
 *
 * `--dry-run` builds and validates the full payload but skips both DB and
 * FAL writes.
 */
final class ImportCommand extends Command
{
    private const DEFAULT_STORAGE = 1;
    private const DEFAULT_FOLDER = '1:/static-html-import/';
    private const DEFAULT_THRESHOLD = 0.6;

    public function __construct(
        private readonly LocalFilesAdapter $files,
        private readonly StructuralAnalyzer $analyzer,
        private readonly YamlMappingLoader $mappings,
        private readonly ContentImporter $contentImporter,
        private readonly DataHandlerAdapterInterface $dbAdapter,
        private readonly FalImporterInterface $falImporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Persist analyzed blocks as tt_content records and FAL assets.')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to a directory of static HTML files')
            ->addArgument('mapping', InputArgument::REQUIRED, 'Path to a mapping YAML file or directory of mappings')
            ->addOption('target-pid', null, InputOption::VALUE_REQUIRED, 'Target page uid for tt_content records (required)')
            ->addOption('storage', null, InputOption::VALUE_REQUIRED, 'FAL storage uid', (string)self::DEFAULT_STORAGE)
            ->addOption('folder', null, InputOption::VALUE_REQUIRED, 'FAL target folder', self::DEFAULT_FOLDER)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate the payload without DB or FAL writes')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip AI fallback in FieldTransformer')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Confidence threshold for AI fallback (0.0-1.0)', (string)self::DEFAULT_THRESHOLD)
            ->addOption('review', null, InputOption::VALUE_REQUIRED, 'CSV path for the review report (validation failures)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targetPidRaw = $input->getOption('target-pid');
        if (!is_string($targetPidRaw) || trim($targetPidRaw) === '' || !ctype_digit(ltrim($targetPidRaw, '-'))) {
            $output->writeln('<error>--target-pid is required and must be an integer</error>');
            return Command::INVALID;
        }
        $targetPid = (int)$targetPidRaw;
        if ($targetPid <= 0) {
            $output->writeln('<error>--target-pid must be a positive integer</error>');
            return Command::INVALID;
        }

        $threshold = (float)$input->getOption('threshold');
        if ($threshold < 0.0 || $threshold > 1.0) {
            $output->writeln('<error>--threshold must be in [0.0, 1.0]</error>');
            return Command::INVALID;
        }

        $source = (string)$input->getArgument('source');
        $sourceReal = realpath($source);
        if ($sourceReal === false || !is_dir($sourceReal)) {
            $output->writeln(sprintf('<error>Source is not a directory: %s</error>', $source));
            return Command::FAILURE;
        }

        $mappingArg = (string)$input->getArgument('mapping');
        try {
            $mappings = $this->loadMappings($mappingArg);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>Mapping load failed: %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }
        if ($mappings === []) {
            $output->writeln('<error>No mappings loaded from the given path</error>');
            return Command::FAILURE;
        }

        $folder = (string)$input->getOption('folder');
        $dryRun = (bool)$input->getOption('dry-run');

        $imported = [];
        $errors = [];
        $blockCount = 0;
        $skippedNoMapping = 0;

        try {
            foreach ($this->files->read($source) as $document) {
                foreach ($this->analyzer->analyze($document) as $block) {
                    $blockCount++;
                    $mapping = $this->pickMapping($mappings, $block);
                    if ($mapping === null) {
                        $skippedNoMapping++;
                        $errors[] = [
                            'document' => $document->path,
                            'block_id' => $block->id,
                            'phase' => 'mapping-lookup',
                            'message' => sprintf(
                                'No mapping matched candidates [%s]; available cTypes: [%s]',
                                implode(', ', $block->candidateTypes) ?: '(none)',
                                implode(', ', array_keys($mappings)),
                            ),
                        ];
                        continue;
                    }

                    $payload = $this->contentImporter->buildPayload($block, $mapping);
                    [$payload, $fileReferences] = $this->resolveImageFields(
                        $payload,
                        $mapping,
                        $sourceReal,
                        $folder,
                        $errors,
                        $document->path,
                        $block,
                    );

                    if ($dryRun) {
                        $imported[] = [
                            'document' => $document->path,
                            'block' => $block,
                            'mapping' => $mapping,
                            'uid' => 0,
                            'updated' => false,
                            'payload' => $payload,
                            'fileReferences' => $fileReferences,
                        ];
                        continue;
                    }

                    try {
                        $existingUid = $this->dbAdapter->findByBlockId($block->id);
                        $uid = $this->dbAdapter->processContent($targetPid, $payload, $existingUid, $fileReferences);
                        $imported[] = [
                            'document' => $document->path,
                            'block' => $block,
                            'mapping' => $mapping,
                            'uid' => $uid,
                            'updated' => $existingUid !== null,
                            'payload' => $payload,
                            'fileReferences' => $fileReferences,
                        ];
                    } catch (Throwable $e) {
                        $errors[] = [
                            'document' => $document->path,
                            'block_id' => $block->id,
                            'phase' => 'datahandler',
                            'message' => $e->getMessage(),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->write($this->renderReport(
            source: $source,
            mappings: $mappings,
            targetPid: $targetPid,
            blockCount: $blockCount,
            skippedNoMapping: $skippedNoMapping,
            imported: $imported,
            errors: $errors,
            dryRun: $dryRun,
        ));

        $reviewPath = $input->getOption('review');
        if (is_string($reviewPath) && $reviewPath !== '') {
            try {
                $written = $this->writeReviewCsv($reviewPath, $errors);
                $output->writeln(sprintf('<info>Review CSV written to %s (%d row(s))</info>', $written, count($errors)));
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>Review CSV write failed: %s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, ImportMapping> keyed by cType
     */
    private function loadMappings(string $path): array
    {
        if (is_dir($path)) {
            return $this->mappings->loadDirectory($path);
        }
        $single = $this->mappings->loadFile($path);
        return [$single->cType => $single];
    }

    /**
     * Picks the most-preferred mapping for a block: first cType in
     * `block->candidateTypes` that has a corresponding YAML mapping wins.
     *
     * @param array<string, ImportMapping> $mappings
     */
    private function pickMapping(array $mappings, ContentBlock $block): ?ImportMapping
    {
        foreach ($block->candidateTypes as $candidate) {
            if (isset($mappings[$candidate])) {
                return $mappings[$candidate];
            }
        }
        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{document: string, block_id: string, phase: string, message: string}> $errors  by reference
     * @return array{0: array<string, mixed>, 1: array<string, list<int>>}  rewritten payload and file references
     */
    private function resolveImageFields(
        array $payload,
        ImportMapping $mapping,
        string $sourceReal,
        string $folder,
        array &$errors,
        string $documentPath,
        ContentBlock $block,
    ): array {
        $references = [];

        foreach ($mapping->fields as $columnName => $field) {
            if ($field->type !== 'image') {
                continue;
            }
            if (!isset($payload[$columnName]) || !is_string($payload[$columnName]) || $payload[$columnName] === '') {
                continue;
            }
            $resolved = $this->resolveImagePath($sourceReal, (string)$payload[$columnName]);
            if ($resolved === null) {
                $errors[] = [
                    'document' => $documentPath,
                    'block_id' => $block->id,
                    'phase' => 'image-resolve',
                    'message' => sprintf('Cannot resolve image "%s" within source dir', (string)$payload[$columnName]),
                ];
                unset($payload[$columnName]);
                continue;
            }
            try {
                $fileUid = $this->falImporter->importFile($resolved, $folder);
            } catch (Throwable $e) {
                $errors[] = [
                    'document' => $documentPath,
                    'block_id' => $block->id,
                    'phase' => 'fal-import',
                    'message' => $e->getMessage(),
                ];
                unset($payload[$columnName]);
                continue;
            }
            $references[(string)$columnName] ??= [];
            $references[(string)$columnName][] = $fileUid;
            unset($payload[$columnName]);
        }
        return [$payload, $references];
    }

    private function resolveImagePath(string $sourceDir, string $imagePath): ?string
    {
        if (preg_match('#^(https?:|data:|//)#i', $imagePath) === 1) {
            return null;
        }
        $imagePath = preg_replace('/[?#].*$/', '', $imagePath) ?? $imagePath;
        if ($imagePath === '') {
            return null;
        }

        $candidate = str_starts_with($imagePath, '/')
            ? $sourceDir . $imagePath
            : $sourceDir . '/' . $imagePath;

        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $sourceSep = rtrim($sourceDir, '/') . '/';
        return str_starts_with($real, $sourceSep) ? $real : null;
    }

    /**
     * @param array<string, ImportMapping> $mappings
     * @param list<array{document: string, block: ContentBlock, mapping: ImportMapping, uid: int, updated: bool, payload: array<string, mixed>, fileReferences: array<string, list<int>>}> $imported
     * @param list<array{document: string, block_id: string, phase: string, message: string}> $errors
     */
    private function renderReport(
        string $source,
        array $mappings,
        int $targetPid,
        int $blockCount,
        int $skippedNoMapping,
        array $imported,
        array $errors,
        bool $dryRun,
    ): string {
        $created = array_filter($imported, static fn (array $r): bool => !$r['updated']);
        $updated = array_filter($imported, static fn (array $r): bool => $r['updated']);

        $out = "# Static HTML Import Report\n\n";
        $out .= sprintf("- Source: `%s`\n", $source);
        $out .= sprintf("- Mappings (%d cType(s)): %s\n", count($mappings), implode(', ', array_keys($mappings)));
        $out .= sprintf("- Target page uid: %d\n", $targetPid);
        $out .= sprintf("- Mode: %s\n", $dryRun ? 'dry-run' : 'live');
        $out .= sprintf("- Blocks processed: %d (skipped without mapping: %d)\n", $blockCount, $skippedNoMapping);
        if ($dryRun) {
            $out .= sprintf("- Would create: %d, would update: %d\n", count($created), count($updated));
        } else {
            $out .= sprintf("- Created: %d, updated: %d\n", count($created), count($updated));
        }
        $out .= sprintf("- Errors: %d\n\n", count($errors));

        if ($imported !== []) {
            $verb = $dryRun ? 'Planned writes' : 'Persisted records';
            $out .= sprintf("## %s\n\n", $verb);
            $out .= "| Document | Block | cType | uid | Refs | Action |\n|---|---|---|---|---|---|\n";
            foreach ($imported as $row) {
                $action = $dryRun ? 'preview' : ($row['updated'] ? 'update' : 'create');
                $refsCount = array_sum(array_map('count', $row['fileReferences']));
                $out .= sprintf(
                    "| %s | `%s` | %s | %s | %d | %s |\n",
                    $row['document'],
                    substr($row['block']->id, 0, 12),
                    $row['mapping']->cType,
                    $dryRun ? '-' : (string)$row['uid'],
                    $refsCount,
                    $action,
                );
            }
            $out .= "\n";
        }

        if ($errors !== []) {
            $out .= "## Errors\n\n";
            $out .= "| Document | Block | Phase | Message |\n|---|---|---|---|\n";
            foreach ($errors as $err) {
                $out .= sprintf(
                    "| %s | `%s` | %s | %s |\n",
                    $err['document'],
                    substr($err['block_id'], 0, 12),
                    $err['phase'],
                    str_replace('|', '\\|', $err['message']),
                );
            }
            $out .= "\n";
        }

        return $out;
    }

    /**
     * @param list<array{document: string, block_id: string, phase: string, message: string}> $errors
     */
    private function writeReviewCsv(string $path, array $errors): string
    {
        if (trim($path) === '' || str_contains($path, "\0")) {
            throw new \RuntimeException('Review path must not be empty or contain null bytes');
        }
        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw new \RuntimeException(sprintf('Review path must be absolute: %s', $path));
        }
        $segments = explode('/', str_replace('\\', '/', $path));
        if (in_array('..', $segments, true)) {
            throw new \RuntimeException(sprintf('Review path must not contain ".." segments: %s', $path));
        }
        $realDir = realpath(dirname($path));
        if ($realDir === false || !is_dir($realDir)) {
            throw new \RuntimeException(sprintf('Parent directory must exist: %s', dirname($path)));
        }
        $resolved = $realDir . DIRECTORY_SEPARATOR . basename($path);

        $handle = fopen($resolved, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open for writing: %s', $resolved));
        }
        try {
            fputcsv($handle, ['document', 'block_id', 'phase', 'message'], escape: '\\');
            foreach ($errors as $err) {
                fputcsv($handle, [$err['document'], $err['block_id'], $err['phase'], $err['message']], escape: '\\');
            }
        } finally {
            fclose($handle);
        }
        return $resolved;
    }
}
