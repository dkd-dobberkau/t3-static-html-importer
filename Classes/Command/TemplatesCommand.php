<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Skeleton placeholder for `t3:static-html:templates`.
 *
 * Full implementation is tracked in issue #9.
 */
final class TemplatesCommand extends Command
{
    protected function configure(): void
    {
        $this->setDescription('Generate Fluid partials from analyzed sources (skeleton, see #9).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>templates: not yet implemented (see issue #9).</comment>');
        return Command::SUCCESS;
    }
}
