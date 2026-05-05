<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Skeleton placeholder for `t3:static-html:analyze`.
 *
 * Full implementation is tracked in issue #7.
 */
final class AnalyzeCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Analyze static HTML sources and report structure (skeleton, see #7).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>analyze: not yet implemented (see issue #7).</comment>');
        return Command::SUCCESS;
    }
}
