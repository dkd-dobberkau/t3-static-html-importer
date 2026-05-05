<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierInterface;
use T3x\StaticHtmlImporter\Service\Analyzer\StructuralAnalyzer;
use T3x\StaticHtmlImporter\Service\Source\LocalFilesAdapter;
use T3x\StaticHtmlImporter\Service\Template\FluidPartialGeneratorInterface;
use Throwable;

/**
 * Reads HTML sources, analyses block structure, and distils Fluid partials,
 * per-cType templates and a default layout under a target Resources/Private
 * directory. Run after `analyze`, before `import`.
 *
 * AI classification (when not `--no-ai`) elevates blocks below `--threshold`
 * by prepending the AI's verdict to their candidate types so the template is
 * named after the more confident type.
 */
final class TemplatesCommand extends Command
{
    private const DEFAULT_THRESHOLD = 0.6;

    public function __construct(
        private readonly LocalFilesAdapter $files,
        private readonly StructuralAnalyzer $analyzer,
        private readonly AiClassifierInterface $ai,
        private readonly FluidPartialGeneratorInterface $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate Fluid partials, templates and a default layout from analyzed sources.')
            ->addArgument('source', InputArgument::REQUIRED, 'Path to a directory of static HTML files')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Where to write Resources/Private/{Layouts,Templates,Partials} (default: extension Resources/Private)')
            ->addOption('no-ai', null, InputOption::VALUE_NONE, 'Skip AI classification; use heuristic candidate types only')
            ->addOption('threshold', null, InputOption::VALUE_REQUIRED, 'Confidence threshold below which the AI is consulted (0.0-1.0)', (string)self::DEFAULT_THRESHOLD)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List planned writes without touching the filesystem');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $threshold = (float)$input->getOption('threshold');
        if ($threshold < 0.0 || $threshold > 1.0) {
            $output->writeln('<error>--threshold must be in [0.0, 1.0]</error>');
            return Command::INVALID;
        }

        $useAi = !(bool)$input->getOption('no-ai');
        $dryRun = (bool)$input->getOption('dry-run');
        $source = (string)$input->getArgument('source');

        $targetOption = $input->getOption('target');
        $target = is_string($targetOption) && $targetOption !== ''
            ? $targetOption
            : $this->defaultTarget();

        try {
            $blocks = $this->collectBlocks($source, $useAi, $threshold);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        try {
            $written = $this->generator->generate($blocks, $target, $dryRun);
        } catch (Throwable $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->write($this->renderReport($source, $target, count($blocks), $written, $useAi, $threshold, $dryRun));
        return Command::SUCCESS;
    }

    /**
     * @return list<ContentBlock>
     */
    private function collectBlocks(string $source, bool $useAi, float $threshold): array
    {
        $all = [];
        foreach ($this->files->read($source) as $document) {
            foreach ($this->analyzer->analyze($document) as $block) {
                $all[] = $useAi ? $this->maybeReclassify($block, $threshold) : $block;
            }
        }
        return $all;
    }

    private function maybeReclassify(ContentBlock $block, float $threshold): ContentBlock
    {
        if ($block->confidence >= $threshold) {
            return $block;
        }
        try {
            $classification = $this->ai->classifyBlock($block->html, $block->candidateTypes);
        } catch (Throwable) {
            return $block;
        }

        // Prepend the AI verdict so it becomes the primary candidate type that
        // FluidPartialGenerator uses when picking the template filename.
        $candidates = array_values(array_unique(array_merge(
            [$classification->type],
            $block->candidateTypes,
        )));

        return new ContentBlock(
            id: $block->id,
            html: $block->html,
            tag: $block->tag,
            candidateTypes: $candidates,
            confidence: $classification->confidence,
            attributes: $block->attributes,
        );
    }

    /**
     * @param list<string> $written
     */
    private function renderReport(
        string $source,
        string $target,
        int $blockCount,
        array $written,
        bool $useAi,
        float $threshold,
        bool $dryRun,
    ): string {
        $mode = match (true) {
            $dryRun && $useAi => 'AI-assisted, dry-run',
            $dryRun => 'deterministic, dry-run',
            $useAi => 'AI-assisted',
            default => 'deterministic',
        };

        $out = "# Static HTML Templates Generation Report\n\n";
        $out .= sprintf("- Source: `%s`\n", $source);
        $out .= sprintf("- Target: `%s`\n", $target);
        $out .= sprintf("- Blocks: %d\n", $blockCount);
        $out .= sprintf("- Mode: %s\n", $mode);
        $out .= sprintf("- Threshold: %.2f\n", $threshold);
        $out .= "\n";

        $verb = $dryRun ? 'Would write' : 'Wrote';
        $out .= sprintf("## %s (%d)\n\n", $verb, count($written));
        if ($written === []) {
            $out .= "_All targets already up to date._\n";
            return $out;
        }
        sort($written);
        foreach ($written as $path) {
            $out .= sprintf("- %s\n", $path);
        }
        return $out;
    }

    private function defaultTarget(): string
    {
        // Classes/Command/TemplatesCommand.php -> extension root -> Resources/Private
        return dirname(__DIR__, 2) . '/Resources/Private';
    }
}
