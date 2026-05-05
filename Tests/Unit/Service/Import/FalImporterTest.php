<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Import;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\AssetMetadata;
use T3x\StaticHtmlImporter\Domain\Model\ClassificationResult;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierInterface;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;
use T3x\StaticHtmlImporter\Service\Import\FalAdapterInterface;
use T3x\StaticHtmlImporter\Service\Import\FalImporter;

final class FalImporterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/t3shi-fal-' . uniqid('', true);
        mkdir($this->root, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testReturnsExistingUidWhenSha1MatchesDedupe(): void
    {
        $path = $this->writeFile('hero.jpg', 'image-bytes');
        $sha1 = sha1('image-bytes');

        $adapter = $this->newAdapter();
        $adapter->existingUidBySha1[$sha1] = 99;
        $importer = new FalImporter($adapter, new AiClassifierMock());

        $uid = $importer->importFile($path, '1:/uploads/');

        self::assertSame(99, $uid);
        self::assertSame([], $adapter->addedFiles, 'no addFile call when dedupe hits');
    }

    public function testInsertsFileWhenNotInDedupeIndex(): void
    {
        $path = $this->writeFile('hero.jpg', 'image-bytes');

        $adapter = $this->newAdapter();
        $importer = new FalImporter($adapter, new AiClassifierMock());

        $uid = $importer->importFile($path, '1:/uploads/');

        self::assertSame(1, $uid);
        self::assertCount(1, $adapter->addedFiles);
        self::assertSame(1, $adapter->addedFiles[0]['storageUid']);
        self::assertSame('/uploads/', $adapter->addedFiles[0]['folderPath']);
    }

    public function testParsesStoragePrefixedTarget(): void
    {
        $path = $this->writeFile('hero.jpg', 'image-bytes');
        $adapter = $this->newAdapter();
        $importer = new FalImporter($adapter, new AiClassifierMock(), defaultStorageUid: 5);

        $importer->importFile($path, '7:/agency/');

        self::assertSame(7, $adapter->addedFiles[0]['storageUid']);
        self::assertSame('/agency/', $adapter->addedFiles[0]['folderPath']);
    }

    public function testFallsBackToDefaultStorageWhenNoPrefix(): void
    {
        $path = $this->writeFile('hero.jpg', 'image-bytes');
        $adapter = $this->newAdapter();
        $importer = new FalImporter($adapter, new AiClassifierMock(), defaultStorageUid: 3);

        $importer->importFile($path, '/static-imports/');

        self::assertSame(3, $adapter->addedFiles[0]['storageUid']);
    }

    public function testEnrichesMetadataWhenFlagIsTrue(): void
    {
        $path = $this->writeFile('hero-banner.jpg', 'image-bytes');
        $adapter = $this->newAdapter();
        $importer = new FalImporter(
            $adapter,
            new AiClassifierMock(),
            enrichMetadata: true,
        );

        $importer->importFile($path, '1:/uploads/');

        self::assertCount(1, $adapter->metadataUpdates);
        $update = $adapter->metadataUpdates[0];
        self::assertSame(1, $update['fileUid']);
        self::assertSame('Hero Banner', $update['metadata']['alternative']);
        self::assertSame('Hero Banner', $update['metadata']['title']);
    }

    public function testSkipsMetadataWhenFlagIsFalse(): void
    {
        $path = $this->writeFile('hero.jpg', 'image-bytes');
        $adapter = $this->newAdapter();
        $importer = new FalImporter($adapter, new AiClassifierMock(), enrichMetadata: false);

        $importer->importFile($path, '1:/uploads/');

        self::assertSame([], $adapter->metadataUpdates);
    }

    public function testMetadataFailureDoesNotAbortImport(): void
    {
        $path = $this->writeFile('hero.jpg', 'image-bytes');
        $adapter = $this->newAdapter();
        $alwaysFailingAi = $this->failingAi();
        $importer = new FalImporter($adapter, $alwaysFailingAi, enrichMetadata: true);

        $uid = $importer->importFile($path, '1:/uploads/');

        self::assertSame(1, $uid, 'file is added despite metadata failure');
        self::assertSame([], $adapter->metadataUpdates);
    }

    public function testThrowsOnNonExistentSource(): void
    {
        $importer = new FalImporter($this->newAdapter(), new AiClassifierMock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source file is not readable');

        $importer->importFile('/does/not/exist.jpg', '1:/uploads/');
    }

    public function testEnforcesSourceBaseDirWhenConfigured(): void
    {
        $allowed = $this->writeFile('inside.jpg', 'image-bytes');
        $forbidden = sys_get_temp_dir() . '/t3shi-outside-' . uniqid('', true) . '.jpg';
        file_put_contents($forbidden, 'image-bytes');

        try {
            $importer = new FalImporter(
                $this->newAdapter(),
                new AiClassifierMock(),
                sourceBaseDir: $this->root,
            );

            // Allowed: returns a uid
            $uid = $importer->importFile($allowed, '1:/uploads/');
            self::assertSame(1, $uid);

            // Forbidden: throws
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('outside sourceBaseDir');
            $importer->importFile($forbidden, '1:/uploads/');
        } finally {
            @unlink($forbidden);
        }
    }

    private function failingAi(): AiClassifierInterface
    {
        return new class implements AiClassifierInterface {
            public function classifyBlock(string $domFragment, array $candidateTypes): ClassificationResult
            {
                throw new RuntimeException('boom');
            }
            public function extractFieldValue(string $domFragment, FieldDefinition $field): ?string
            {
                throw new RuntimeException('boom');
            }
            public function enrichAssetMetadata(string $imagePath): AssetMetadata
            {
                throw new RuntimeException('boom');
            }
        };
    }

    /**
     * @return FalAdapterInterface&object{addedFiles: list<array{sourcePath: string, storageUid: int, folderPath: string}>, metadataUpdates: list<array{fileUid: int, metadata: array<string, mixed>}>, existingUidBySha1: array<string, int>}
     */
    private function newAdapter(): FalAdapterInterface
    {
        return new class implements FalAdapterInterface {
            /** @var list<array{sourcePath: string, storageUid: int, folderPath: string}> */
            public array $addedFiles = [];
            /** @var list<array{fileUid: int, metadata: array<string, mixed>}> */
            public array $metadataUpdates = [];
            /** @var array<string, int> */
            public array $existingUidBySha1 = [];

            private int $nextUid = 1;

            public function findUidBySha1(string $sha1): ?int
            {
                return $this->existingUidBySha1[$sha1] ?? null;
            }
            public function addFile(string $sourcePath, int $storageUid, string $folderPath): int
            {
                $uid = $this->nextUid++;
                $this->addedFiles[] = compact('sourcePath', 'storageUid', 'folderPath');
                return $uid;
            }
            public function updateMetadata(int $fileUid, array $metadata): void
            {
                $this->metadataUpdates[] = compact('fileUid', 'metadata');
            }
        };
    }

    private function writeFile(string $name, string $contents): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        rmdir($path);
    }
}
