<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Functional\Import;

use PHPUnit\Framework\Attributes\Test;
use T3x\StaticHtmlImporter\Service\Import\DataHandlerAdapter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises the production DataHandlerAdapter against a real TYPO3 instance
 * (sqlite via typo3/testing-framework). Unit tests use a recording fake;
 * this case verifies the wrapper actually plays nicely with DataHandler,
 * tt_content TCA and our dedupe column.
 */
final class DataHandlerAdapterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'core',
        'backend',
        'frontend',
        'extbase',
        'fluid',
    ];

    protected array $testExtensionsToLoad = [
        'b13/aim',
        't3x/static-html-importer',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DataHandlerAdapter/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/DataHandlerAdapter/pages.csv');
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function insertsNewContentRecord(): void
    {
        $uid = (new DataHandlerAdapter())->processContent(1, [
            'CType' => 'text',
            'header' => 'Hello',
            'tx_static_html_importer_block_id' => 'block-insert',
        ], null);

        self::assertGreaterThan(0, $uid);

        $row = $this->fetchContentRow($uid);
        self::assertSame('text', $row['CType']);
        self::assertSame('Hello', $row['header']);
        self::assertSame('block-insert', $row['tx_static_html_importer_block_id']);
        self::assertSame(1, (int)$row['pid']);
    }

    #[Test]
    public function findByBlockIdReturnsExistingUidOrNull(): void
    {
        $adapter = new DataHandlerAdapter();
        $uid = $adapter->processContent(1, [
            'CType' => 'text',
            'header' => 'Findable',
            'tx_static_html_importer_block_id' => 'block-find',
        ], null);

        self::assertSame($uid, $adapter->findByBlockId('block-find'));
        self::assertNull($adapter->findByBlockId('does-not-exist'));
    }

    #[Test]
    public function reRunWithSameBlockIdUpdatesInsteadOfDuplicating(): void
    {
        $adapter = new DataHandlerAdapter();
        $firstUid = $adapter->processContent(1, [
            'CType' => 'text',
            'header' => 'Original',
            'tx_static_html_importer_block_id' => 'block-rerun',
        ], null);

        $existingUid = $adapter->findByBlockId('block-rerun');
        self::assertSame($firstUid, $existingUid);

        $secondUid = $adapter->processContent(1, [
            'CType' => 'text',
            'header' => 'Updated',
            'tx_static_html_importer_block_id' => 'block-rerun',
        ], $existingUid);

        self::assertSame($firstUid, $secondUid);

        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['uid', 'header'], 'tt_content', ['tx_static_html_importer_block_id' => 'block-rerun'])
            ->fetchAllAssociative();

        self::assertCount(1, $rows);
        self::assertSame('Updated', $rows[0]['header']);
    }

    #[Test]
    public function payloadReachesDataHandlerWithoutErrors(): void
    {
        $uid = (new DataHandlerAdapter())->processContent(1, [
            'CType' => 'textmedia',
            'header' => 'With body',
            'bodytext' => '<p>Content</p>',
            'tx_static_html_importer_block_id' => 'block-textmedia',
        ], null);

        $row = $this->fetchContentRow($uid);
        self::assertSame('textmedia', $row['CType']);
        self::assertSame('<p>Content</p>', $row['bodytext']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchContentRow(int $uid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['*'], 'tt_content', ['uid' => $uid])
            ->fetchAssociative();

        self::assertIsArray($row, 'tt_content row not found for uid ' . $uid);
        return $row;
    }
}
