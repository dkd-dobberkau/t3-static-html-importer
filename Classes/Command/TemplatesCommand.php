<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Skeleton placeholder for `t3:static-html:templates`. Logs a summary of the
 * planned scope and exits successfully so CI pipelines that probe `bin/typo3
 * list` keep working. Wiring follows in the next phase, see
 * FluidPartialGeneratorInterface.
 */
final class TemplatesCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Generate Fluid partials, layouts and templates from analyzed sources (skeleton).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>templates: not yet implemented.</comment>');
        $output->writeln('');
        $output->writeln('Planned scope:');
        $output->writeln('  - read the analyze report and detected ContentBlocks');
        $output->writeln('  - distil recurring structures into Fluid partials');
        $output->writeln('  - write under Resources/Private/{Templates,Layouts,Partials}/');
        $output->writeln('  - idempotent writes, skip files whose BlockHasher hash is unchanged');
        $output->writeln('');
        $output->writeln('Run `analyze` first, review the report, then run this command,');
        $output->writeln('review the generated templates, then run `import`.');
        $output->writeln('');
        $output->writeln('See FluidPartialGeneratorInterface and PROJECT_BRIEF.md for details.');
        return Command::SUCCESS;
    }
}
