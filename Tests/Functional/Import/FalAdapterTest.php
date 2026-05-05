<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Functional\Import;

use PHPUnit\Framework\Attributes\Test;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;
use T3x\StaticHtmlImporter\Service\Import\DataHandlerAdapter;
use T3x\StaticHtmlImporter\Service\Import\FalAdapter;
use T3x\StaticHtmlImporter\Service\Import\FalImporter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exercises FalAdapter + FalImporter against a real TYPO3 instance:
 * a Local sys_file_storage points at the test instance's fileadmin/,
 * tests add a 1x1 fixture PNG and assert the FAL graph (sys_file +
 * sys_file_metadata) plus SHA1 dedupe and the image-field
 * sys_file_reference roundtrip via DataHandlerAdapter.
 */
final class FalAdapterTest extends FunctionalTestCase
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

    private const FIXTURE_IMAGE = __DIR__ . '/../Fixtures/FalAdapter/pixel.png';

    private int $storageUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/FalAdapter/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/FalAdapter/pages.csv');
        $this->setUpBackendUser(1);

        $this->storageUid = GeneralUtility::makeInstance(StorageRepository::class)
            ->createLocalStorage('Test fileadmin', 'fileadmin', 'relative', '', true);
    }

    #[Test]
    public function findUidBySha1ReturnsNullWhenNoFileMatches(): void
    {
        self::assertNull((new FalAdapter())->findUidBySha1(sha1('not-imported')));
    }

    #[Test]
    public function addFileCreatesSysFileRowAndReturnsUid(): void
    {
        $adapter = new FalAdapter();
        $uid = $adapter->addFile(self::FIXTURE_IMAGE, $this->storageUid, '/imported');

        self::assertGreaterThan(0, $uid);

        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['*'], 'sys_file', ['uid' => $uid])
            ->fetchAssociative();

        self::assertIsArray($row);
        self::assertSame((int)$this->storageUid, (int)$row['storage']);
        self::assertSame(sha1_file(self::FIXTURE_IMAGE), $row['sha1']);
        self::assertSame('pixel.png', $row['name']);
    }

    #[Test]
    public function findUidBySha1RoundtripsAfterAddFile(): void
    {
        $adapter = new FalAdapter();
        $uid = $adapter->addFile(self::FIXTURE_IMAGE, $this->storageUid, '/imported');

        self::assertSame($uid, $adapter->findUidBySha1(sha1_file(self::FIXTURE_IMAGE)));
    }

    #[Test]
    public function updateMetadataInsertsThenUpdates(): void
    {
        $adapter = new FalAdapter();
        $fileUid = $adapter->addFile(self::FIXTURE_IMAGE, $this->storageUid, '/imported');

        $adapter->updateMetadata($fileUid, [
            'alternative' => 'Alt one',
            'title' => 'Title one',
        ]);
        $first = $this->fetchMetadata($fileUid);
        self::assertSame('Alt one', $first['alternative']);
        self::assertSame('Title one', $first['title']);

        $adapter->updateMetadata($fileUid, ['alternative' => 'Alt two']);
        $second = $this->fetchMetadata($fileUid);
        self::assertSame((int)$first['uid'], (int)$second['uid'], 'metadata row should be updated, not duplicated');
        self::assertSame('Alt two', $second['alternative']);
        self::assertSame('Title one', $second['title']);
    }

    #[Test]
    public function falImporterDedupesOnSha1AcrossReRuns(): void
    {
        $importer = new FalImporter(new FalAdapter(), new AiClassifierMock(), $this->storageUid);

        $first = $importer->importFile(self::FIXTURE_IMAGE, $this->storageUid . ':/imported');
        $second = $importer->importFile(self::FIXTURE_IMAGE, $this->storageUid . ':/imported');

        self::assertSame($first, $second, 'second import of identical content must reuse the existing sys_file uid');

        $rows = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file')
            ->select(['uid'], 'sys_file', ['sha1' => sha1_file(self::FIXTURE_IMAGE)])
            ->fetchAllAssociative();
        self::assertCount(1, $rows, 'sha1 dedupe must keep a single sys_file row');
    }

    #[Test]
    public function imageReferenceLinksContentToFileEndToEnd(): void
    {
        $importer = new FalImporter(new FalAdapter(), new AiClassifierMock(), $this->storageUid);
        $fileUid = $importer->importFile(self::FIXTURE_IMAGE, $this->storageUid . ':/imported');

        $contentUid = (new DataHandlerAdapter())->processContent(
            1,
            [
                'CType' => 'image',
                'header' => 'With image',
                'tx_static_html_importer_block_id' => 'block-image',
            ],
            null,
            ['image' => [$fileUid]],
        );

        self::assertGreaterThan(0, $contentUid);

        $references = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->select(
                ['*'],
                'sys_file_reference',
                ['tablenames' => 'tt_content', 'uid_foreign' => $contentUid, 'fieldname' => 'image'],
            )
            ->fetchAllAssociative();

        self::assertCount(1, $references);
        self::assertSame($fileUid, (int)$references[0]['uid_local']);
        self::assertSame(1, (int)$references[0]['sorting_foreign']);

        $contentRow = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['image'], 'tt_content', ['uid' => $contentUid])
            ->fetchAssociative();
        self::assertSame(1, (int)$contentRow['image'], 'tt_content.image must reflect the reference count');
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMetadata(int $fileUid): array
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->select(['*'], 'sys_file_metadata', ['file' => $fileUid])
            ->fetchAssociative();

        self::assertIsArray($row, 'sys_file_metadata row missing for file ' . $fileUid);
        return $row;
    }
}
