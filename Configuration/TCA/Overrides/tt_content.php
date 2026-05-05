<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Adds the dedupe column for the static HTML importer to tt_content.
 *
 * The column carries the BlockHasher hash of the source block so re-imports
 * update the existing record instead of inserting a duplicate. It is
 * read-only in the backend; the importer is the only writer.
 */
ExtensionManagementUtility::addTCAcolumns(
    'tt_content',
    [
        'tx_static_html_importer_block_id' => [
            'exclude' => true,
            'label' => 'Static HTML Importer: source block hash',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'readOnly' => true,
            ],
        ],
    ],
);
