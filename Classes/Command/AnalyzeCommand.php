<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;
use T3x\StaticHtmlImporter\Domain\Model\SourceDocument;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierInterface;
use T3x\StaticHtmlImporter\Service\Analyzer\AnalyzedBlock;
use T3x\StaticHtmlImporter\Service\Analyzer\StructuralAnalyzer;
use T3x\StaticHtmlImporter\Service\Mapping\YamlMappingLoader;
use T3x\StaticHtmlImporter\Service\Source\SourceAdapterRegistry;
use Throwable;

/**
 * Reads HTML sources, analyses block structure, escalates low-confidence blocks
 * to the AiClassifier, and emits a markdown report plus an optional review
 * report for items that stay below the threshold.
 *
 * The pipeline runs deterministically with `--no-ai`; otherwise blocks below
 * `--threshold` are sent to the AiClassifier (cached per input hash). The
 * report ends with one suggested YAML mapping stub per detected cType.
 */
final class AnalyzeCommand extends Command
{
    private const DEFAULT_THRESHOLD = 0.6;

    public function __construct(
        private readonly SourceAdapterRegistry $sources,
        private readonly StructuralAnalyzer $analyzer,
        private readonly AiClassifierInterface $ai,
        private readonly YamlMappingLoader $mappings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Analyze static HTML sources and emit a structure report.')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to a directory of static HTML files')
            ->addArgument('mapping', InputArgument::OPTIONAL, 'Optional path to a mapping YAML file or directory of mappings')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip the AI classifier; rely on deterministic heuristics only')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Confidence threshold below which the AI is consulted (0.0-1.0)', (string)self::DEFAULT_THRESHOLD)
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the markdown report to this absolute file path (parent dir must exist)')
            ->addOption('review', null, InputOption::VALUE_REQUIRED, 'Write a review report (low-confidence blocks) to this absolute file path')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing report/review files instead of failing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (float)$input->getOption('threshold');
        if ($threshold < 0.0 || $threshold > 1.0) {
            $output->writeln('<error>--threshold must be in [0.0, 1.0]</error>');
            return Command::INVALID;
        }

        $useAi = !(bool)$input->getOption('no-ai');
        $source = (string)$input->getArgument('source');

        $loadedMappings = [];
        $mappingArg = $input->getArgument('mapping');
        if (is_string($mappingArg) && $mappingArg !== '') {
            try {
                $loadedMappings = $this->loadMappings($mappingArg);
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>Mapping load failed: %s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        $route = $this->sources->resolve($source);

        try {
            $analyses = $this->analyzeAll($route['adapter'], $route['source'], $useAi, $threshold);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $force = (bool)$input->getOption('force');

        $report = $this->renderReport($source, $route['name'], $analyses, $loadedMappings, $useAi, $threshold);
        $reportPath = $input->getOption('output');
        if (is_string($reportPath) && $reportPath !== '') {
            try {
                $resolved = $this->writeFile($reportPath, $report, $force);
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
            $output->writeln(sprintf('<info>Report written to %s</info>', $resolved));
        } else {
            $output->write($report);
        }

        $reviewPath = $input->getOption('review');
        if (is_string($reviewPath) && $reviewPath !== '') {
            $review = $this->renderReview($analyses, $threshold);
            try {
                $resolved = $this->writeFile($reviewPath, $review, $force);
            } catch (Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
            $output->writeln(sprintf('<info>Review report written to %s</info>', $resolved));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, ImportMapping>
     */
    private function loadMappings(string $path): array
    {
        if (is_dir($path)) {
            return $this->mappings->loadDirectory($path);
        }
        $mapping = $this->mappings->loadFile($path);
        return [$mapping->cType => $mapping];
    }

    /**
     * @return list<array{document: SourceDocument, blocks: list<AnalyzedBlock>}>
     */
    private function analyzeAll(\T3x\StaticHtmlImporter\Service\Source\SourceAdapterInterface $adapter, string $source, bool $useAi, float $threshold): array
    {
        $analyses = [];
        foreach ($adapter->read($source) as $document) {
            $blocks = $this->analyzer->analyze($document);
            $enriched = [];
            foreach ($blocks as $block) {
                $classification = null;
                if ($useAi && $block->confidence < $threshold) {
                    try {
                        $classification = $this->ai->classifyBlock($block->html, $block->candidateTypes);
                    } catch (Throwable) {
                        $classification = null;
                    }
                }
                $enriched[] = new AnalyzedBlock(block: $block, classification: $classification);
            }
            $analyses[] = ['document' => $document, 'blocks' => $enriched];
        }
        return $analyses;
    }

    /**
     * @param list<array{document: SourceDocument, blocks: list<AnalyzedBlock>}> $analyses
     * @param array<string, ImportMapping>                                       $mappings
     */
    private function renderReport(string $source, string $adapterName, array $analyses, array $mappings, bool $useAi, float $threshold): string
    {
        $totalBlocks = array_sum(array_map(static fn (array $a): int => count($a['blocks']), $analyses));
        $mode = $useAi ? 'AI-assisted (mock or real, depending on DI)' : 'deterministic only';

        $out = "# Static HTML Analysis Report\n\n";
        $out .= sprintf("- Source: `%s`\n", $source);
        $out .= sprintf("- Source adapter: %s\n", $adapterName);
        $out .= sprintf("- Documents: %d\n", count($analyses));
        $out .= sprintf("- Blocks: %d\n", $totalBlocks);
        $out .= sprintf("- Mode: %s\n", $mode);
        $out .= sprintf("- Threshold: %.2f\n", $threshold);
        if ($mappings !== []) {
            $out .= sprintf("- Loaded mappings: %s\n", implode(', ', array_keys($mappings)));
        }
        $out .= "\n";

        foreach ($analyses as $analysis) {
            $document = $analysis['document'];
            $out .= sprintf("## %s\n\n", $document->path);
            if ($analysis['blocks'] === []) {
                $out .= "_No blocks detected._\n\n";
                continue;
            }
            foreach ($analysis['blocks'] as $analyzed) {
                $out .= $this->renderBlock($analyzed);
            }
        }

        $out .= $this->renderSuggestedMappings($analyses);
        return $out;
    }

    private function renderBlock(AnalyzedBlock $analyzed): string
    {
        $block = $analyzed->block;
        $line = sprintf(
            "- **%s** [%s] -> `%s` (heuristic %.2f)\n",
            $block->tag,
            $this->summariseAttributes($block->attributes),
            $this->escapeInline($block->candidateTypes[0] ?? 'unknown'),
            $block->confidence,
        );
        $line .= sprintf("  - id: `%s`\n", substr($block->id, 0, 12));
        $line .= sprintf("  - candidates: %s\n", $this->escapeInline(implode(', ', $block->candidateTypes)) ?: '(none)');
        if ($analyzed->classification !== null) {
            $line .= sprintf(
                "  - ai: type=`%s`, conf=%.2f, rationale=\"%s\"\n",
                $this->escapeInline($analyzed->classification->type),
                $analyzed->classification->confidence,
                $this->escapeInline($analyzed->classification->rationale),
            );
        } else {
            $line .= "  - ai: not consulted\n";
        }
        $line .= "\n";
        return $line;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function summariseAttributes(array $attributes): string
    {
        $bits = [];
        foreach (['data-component', 'role', 'id', 'class'] as $key) {
            if (isset($attributes[$key]) && $attributes[$key] !== '') {
                $value = $attributes[$key];
                if ($key === 'class' && strlen($value) > 60) {
                    $value = substr($value, 0, 57) . '...';
                }
                $bits[] = sprintf('%s="%s"', $key, $value);
            }
        }
        return implode(' ', $bits) ?: '-';
    }

    /**
     * @param list<array{document: SourceDocument, blocks: list<AnalyzedBlock>}> $analyses
     */
    private function renderSuggestedMappings(array $analyses): string
    {
        $byType = [];
        foreach ($analyses as $analysis) {
            foreach ($analysis['blocks'] as $analyzed) {
                $type = $analyzed->effectiveType();
                $byType[$type] ??= [];
                $byType[$type][] = $analyzed->block;
            }
        }
        if ($byType === []) {
            return '';
        }
        ksort($byType);

        $out = "## Suggested mappings\n\n";
        $out .= "_Stubs derived from detected cTypes. Refine field descriptions and selector before running templates/import._\n\n";

        foreach ($byType as $type => $blocks) {
            $sample = $blocks[0];
            $stub = [
                'cType' => $type,
                'selector' => $this->guessSelector($sample->tag, $sample->attributes),
                'fields' => [],
            ];
            $out .= "```yaml\n";
            $out .= sprintf("# %d block(s) matched\n", count($blocks));
            $out .= Yaml::dump($stub, 4, 2);
            $out .= "```\n\n";
        }
        return $out;
    }

    /**
     * @param array<string, string> $attributes
     */
    private function guessSelector(string $tag, array $attributes): ?string
    {
        if (isset($attributes['data-component']) && $attributes['data-component'] !== '') {
            return sprintf('%s[data-component="%s"]', $tag, $attributes['data-component']);
        }
        if (isset($attributes['role']) && $attributes['role'] !== '') {
            return sprintf('%s[role="%s"]', $tag, $attributes['role']);
        }
        if (isset($attributes['class']) && $attributes['class'] !== '') {
            $first = strtok($attributes['class'], ' ');
            if (is_string($first) && $first !== '') {
                return sprintf('%s.%s', $tag, $first);
            }
        }
        return $tag;
    }

    /**
     * @param list<array{document: SourceDocument, blocks: list<AnalyzedBlock>}> $analyses
     */
    private function renderReview(array $analyses, float $threshold): string
    {
        $rows = [];
        foreach ($analyses as $analysis) {
            foreach ($analysis['blocks'] as $analyzed) {
                if ($analyzed->effectiveConfidence() >= $threshold) {
                    continue;
                }
                $rows[] = [
                    'document' => $analysis['document']->path,
                    'id' => substr($analyzed->block->id, 0, 12),
                    'type' => $analyzed->effectiveType(),
                    'confidence' => $analyzed->effectiveConfidence(),
                    'source' => $analyzed->source(),
                ];
            }
        }

        $out = sprintf("# Review Report\n\nBlocks below confidence %.2f.\n\n", $threshold);
        if ($rows === []) {
            $out .= "_None._\n";
            return $out;
        }
        $out .= "| Document | Block | Type | Confidence | Source |\n";
        $out .= "|---|---|---|---|---|\n";
        foreach ($rows as $row) {
            $out .= sprintf(
                "| %s | `%s` | %s | %.2f | %s |\n",
                $this->escapeInline($row['document']),
                $row['id'],
                $this->escapeInline($row['type']),
                $row['confidence'],
                $row['source'],
            );
        }
        return $out;
    }

    /**
     * Escape markdown-significant characters in a one-line context: pipe (table
     * separator), backtick (inline code), and collapse newlines so a multi-line
     * LLM rationale cannot break the surrounding structure.
     */
    private function escapeInline(string $value): string
    {
        return str_replace(
            ['|', '`', "\r\n", "\n", "\r"],
            ['\\|', "'", ' ', ' ', ' '],
            $value,
        );
    }

    /**
     * Validates an output path and writes the contents.
     *
     * Path must be absolute, must not contain `..` segments or null bytes, the
     * parent directory must already exist (no recursive mkdir), and existing
     * files are only overwritten when `$force` is true.
     *
     * Returns the resolved (realpath'd) absolute path that was written.
     */
    private function writeFile(string $path, string $contents, bool $force): string
    {
        if (trim($path) === '' || str_contains($path, "\0")) {
            throw new \RuntimeException('Output path must not be empty or contain null bytes');
        }
        if (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1) {
            throw new \RuntimeException(sprintf('Output path must be absolute: %s', $path));
        }
        $segments = explode('/', str_replace('\\', '/', $path));
        if (in_array('..', $segments, true)) {
            throw new \RuntimeException(sprintf('Output path must not contain ".." segments: %s', $path));
        }

        $dir = dirname($path);
        $realDir = realpath($dir);
        if ($realDir === false || !is_dir($realDir)) {
            throw new \RuntimeException(sprintf('Parent directory must exist: %s', $dir));
        }

        $resolved = $realDir . DIRECTORY_SEPARATOR . basename($path);
        if (!$force && file_exists($resolved)) {
            throw new \RuntimeException(sprintf('Refusing to overwrite existing file: %s (use --force)', $resolved));
        }
        if (file_put_contents($resolved, $contents) === false) {
            throw new \RuntimeException(sprintf('Cannot write file: %s', $resolved));
        }
        return $resolved;
    }
}
