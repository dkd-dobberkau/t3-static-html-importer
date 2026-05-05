<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Skeleton placeholder for `t3:static-html:import`.
 *
 * Full implementation is tracked in issue #9.
 */
final class ImportCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Persist tt_content records and FAL assets (skeleton, see #9).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>import: not yet implemented (see issue #9).</comment>');
        return Command::SUCCESS;
    }
}
