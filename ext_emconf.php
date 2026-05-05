<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Static HTML Importer',
    'description' => 'Imports static HTML into Fluid templates, tt_content records and FAL assets, with optional AI-assisted block classification via b13/aim.',
    'category' => 'plugin',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'php' => '8.2.0-8.4.99',
            'typo3' => '13.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
