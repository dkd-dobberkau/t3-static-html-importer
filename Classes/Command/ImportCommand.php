<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Skeleton placeholder for `t3:static-html:import`. Logs a summary of the
 * planned scope and exits successfully so CI pipelines that probe `bin/typo3
 * list` keep working. Wiring follows in the next phase, see
 * ContentImporterInterface and FalImporterInterface.
 */
final class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Persist tt_content records and FAL assets (skeleton).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>import: not yet implemented.</comment>');
        $output->writeln('');
        $output->writeln('Planned scope:');
        $output->writeln('  - persist analysed blocks to tt_content via DataHandler');
        $output->writeln('  - import images and media into FAL, deduplicated by SHA1');
        $output->writeln('  - enrich sys_file_metadata via AiClassifier when configured');
        $output->writeln('  - dry-run support, idempotent re-runs by ContentBlock id');
        $output->writeln('');
        $output->writeln('Always run after `analyze` and `templates`. Only commit to the database');
        $output->writeln('once the analyze report has been reviewed.');
        $output->writeln('');
        $output->writeln('See ContentImporterInterface, FalImporterInterface and PROJECT_BRIEF.md.');
        return Command::SUCCESS;
    }
}
