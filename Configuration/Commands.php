<?php

declare(strict_types=1);

use T3x\StaticHtmlImporter\Command\AnalyzeCommand;
use T3x\StaticHtmlImporter\Command\ImportCommand;
use T3x\StaticHtmlImporter\Command\TemplatesCommand;

return [
    't3:static-html:analyze' => [
        'class' => AnalyzeCommand::class,
        'schedulable' => false,
    ],
    't3:static-html:templates' => [
        'class' => TemplatesCommand::class,
        'schedulable' => false,
    ],
    't3:static-html:import' => [
        'class' => ImportCommand::class,
        'schedulable' => false,
    ],
];
