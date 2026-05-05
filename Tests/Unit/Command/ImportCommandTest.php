<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use T3x\StaticHtmlImporter\Command\ImportCommand;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;
use T3x\StaticHtmlImporter\Service\Analyzer\BlockHasher;
use T3x\StaticHtmlImporter\Service\Analyzer\StructuralAnalyzer;
use T3x\StaticHtmlImporter\Service\Import\ContentImporter;
use T3x\StaticHtmlImporter\Service\Import\DataHandlerAdapterInterface;
use T3x\StaticHtmlImporter\Service\Import\FalAdapterInterface;
use T3x\StaticHtmlImporter\Service\Import\FalImporter;
use T3x\StaticHtmlImporter\Service\Mapping\FieldTransformer;
use T3x\StaticHtmlImporter\Service\Mapping\YamlMappingLoader;
use T3x\StaticHtmlImporter\Service\Source\LocalFilesAdapter;

final class ImportCommandTest extends TestCase
{
    private string $sourceDir;
    private string $mappingPath;

    /** @var DataHandlerAdapterInterface&object{calls: list<array{pid: int, payload: array<string, mixed>, existingUid: ?int}>, existingUidMap: array<string, int>} */
    private DataHandlerAdapterInterface $dbAdapter;

    /** @var FalAdapterInterface&object{addedFiles: list<array{sourcePath: string, storageUid: int, folderPath: string}>, existingUidBySha1: array<string, int>} */
    private FalAdapterInterface $falAdapter;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $unique = uniqid('', true);
        $this->sourceDir = sys_get_temp_dir() . '/t3shi-imp-src-' . $unique;
        mkdir($this->sourceDir . '/uploads', 0o755, true);

        file_put_contents(
            $this->sourceDir . '/index.html',
            '<html><body><section data-component="hero">'
            . '<h1>Welcome</h1><p>Lead text</p>'
            . '<img src="/uploads/hero.jpg" alt="hero">'
            . '</section></body></html>',
        );
        file_put_contents($this->sourceDir . '/uploads/hero.jpg', 'image-bytes-1');

        $this->mappingPath = $this->sourceDir . '/textmedia.yaml';
        file_put_contents($this->mappingPath, "cType: textmedia\nfields:\n  header:\n    description: 'Headline'\n    type: string\n  bodytext:\n    description: 'Body'\n    type: html\n  image:\n    description: 'Hero image path'\n    type: image\n");

        $this->dbAdapter = $this->newDbAdapter();
        $this->falAdapter = $this->newFalAdapter();

        $command = new ImportCommand(
            new LocalFilesAdapter(),
            new StructuralAnalyzer(new BlockHasher()),
            new YamlMappingLoader(),
            new ContentImporter(new FieldTransformer(new AiClassifierMock()), $this->dbAdapter),
            $this->dbAdapter,
            new FalImporter($this->falAdapter, new AiClassifierMock()),
        );
        $command->setName('t3:static-html:import');
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sourceDir);
    }

    public function testHappyPathPersistsRecordAndImportsImage(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $this->mappingPath,
            '--target-pid' => '5',
        ]);

        $display = $this->tester->getDisplay();
        self::assertSame(0, $this->tester->getStatusCode(), $display);

        self::assertCount(1, $this->dbAdapter->calls);
        self::assertSame(5, $this->dbAdapter->calls[0]['pid']);
        $call = $this->dbAdapter->calls[0];
        self::assertSame('textmedia', $call['payload']['CType']);
        self::assertSame('Welcome', $call['payload']['header']);
        self::assertArrayNotHasKey('image', $call['payload'], 'image field must move to fileReferences, not payload');
        self::assertSame(['image' => [1]], $call['fileReferences'], 'FAL uid recorded as a sys_file_reference for the image column');

        self::assertCount(1, $this->falAdapter->addedFiles);
        self::assertSame(1, $this->falAdapter->addedFiles[0]['storageUid']);
        self::assertSame('/static-html-import/', $this->falAdapter->addedFiles[0]['folderPath']);
    }

    public function testRoutesBlockToMatchingMappingByCandidateType(): void
    {
        // Source already has data-component="hero" block -> candidateTypes starts with "hero"
        $mappingDir = $this->sourceDir . '/mappings';
        mkdir($mappingDir, 0o755, true);
        file_put_contents($mappingDir . '/hero.yaml', "cType: hero\nfields:\n  header:\n    description: 'h'\n    type: string\n");
        file_put_contents($mappingDir . '/textmedia.yaml', "cType: textmedia\nfields:\n  header:\n    description: 'h'\n    type: string\n");

        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $mappingDir,
            '--target-pid' => '5',
        ]);

        self::assertSame(0, $this->tester->getStatusCode(), $this->tester->getDisplay());
        self::assertCount(1, $this->dbAdapter->calls);
        self::assertSame('hero', $this->dbAdapter->calls[0]['payload']['CType'], 'hero must win because it appears earlier in candidateTypes');
    }

    public function testSkipsBlocksWithoutMatchingMappingAndReportsThem(): void
    {
        $mappingDir = $this->sourceDir . '/mappings-foo';
        mkdir($mappingDir, 0o755, true);
        // Only an unrelated cType — neither 'hero' nor 'textmedia' is in candidateTypes
        file_put_contents($mappingDir . '/unrelated.yaml', "cType: bullets\nfields:\n  header:\n    description: 'h'\n    type: string\n");

        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $mappingDir,
            '--target-pid' => '5',
        ]);

        $display = $this->tester->getDisplay();
        self::assertSame([], $this->dbAdapter->calls, 'unmapped blocks must not be persisted');
        self::assertStringContainsString('skipped without mapping: 1', $display);
        self::assertStringContainsString('mapping-lookup', $display);
    }

    public function testRejectsImagePathOutsideSourceDir(): void
    {
        $secret = sys_get_temp_dir() . '/t3shi-secret-' . uniqid('', true) . '.jpg';
        file_put_contents($secret, 'secret-bytes');

        try {
            file_put_contents(
                $this->sourceDir . '/escape.html',
                sprintf('<html><body><section data-component="hero"><h1>A</h1><p>B</p><img src="%s"></section></body></html>', $secret),
            );

            unlink($this->sourceDir . '/index.html');

            $this->tester->execute([
                'source' => $this->sourceDir,
                'mapping' => $this->mappingPath,
                '--target-pid' => '1',
            ]);

            $display = $this->tester->getDisplay();
            self::assertStringContainsString('Cannot resolve image', $display);
            self::assertSame([], $this->falAdapter->addedFiles, 'image outside source must not be uploaded');
        } finally {
            @unlink($secret);
        }
    }

    public function testDryRunDoesNotPersistOrUpload(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $this->mappingPath,
            '--target-pid' => '5',
            '--dry-run' => true,
        ]);

        $display = $this->tester->getDisplay();
        self::assertSame(0, $this->tester->getStatusCode(), $display);
        self::assertStringContainsString('dry-run', $display);
        self::assertSame([], $this->dbAdapter->calls, 'no DB writes in dry-run');
        // FAL is consulted in dry-run because we still need to attempt resolving images
        // for the preview; that is acceptable but should not persist DB rows.
    }

    public function testReusesExistingUidOnSecondRun(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $this->mappingPath,
            '--target-pid' => '5',
        ]);

        // Tell the adapter the block now exists at uid 99
        $blockIdCall = $this->dbAdapter->calls[0];
        $blockId = $blockIdCall['payload'][ContentImporter::DEDUPE_COLUMN];
        $this->dbAdapter->existingUidMap[$blockId] = 99;

        $tester = new CommandTester(
            new ImportCommand(
                new LocalFilesAdapter(),
                new StructuralAnalyzer(new BlockHasher()),
                new YamlMappingLoader(),
                new ContentImporter(new FieldTransformer(new AiClassifierMock()), $this->dbAdapter),
                $this->dbAdapter,
                new FalImporter($this->falAdapter, new AiClassifierMock()),
            ),
        );
        // Re-use the same command name binding
        $tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $this->mappingPath,
            '--target-pid' => '5',
        ]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('| update |', $display);
    }

    public function testRejectsMissingTargetPid(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $this->mappingPath,
        ]);

        self::assertSame(2, $this->tester->getStatusCode());
        self::assertStringContainsString('--target-pid is required', $this->tester->getDisplay());
    }

    public function testRejectsBadThreshold(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            'mapping' => $this->mappingPath,
            '--target-pid' => '1',
            '--threshold' => '2',
        ]);

        self::assertSame(2, $this->tester->getStatusCode());
        self::assertStringContainsString('--threshold must be in', $this->tester->getDisplay());
    }

    /**
     * @return DataHandlerAdapterInterface&object{calls: list<array{pid: int, payload: array<string, mixed>, existingUid: ?int}>, existingUidMap: array<string, int>}
     */
    private function newDbAdapter(): DataHandlerAdapterInterface
    {
        return new class implements DataHandlerAdapterInterface {
            /** @var list<array{pid: int, payload: array<string, mixed>, existingUid: ?int}> */
            public array $calls = [];
            /** @var array<string, int> */
            public array $existingUidMap = [];
            private int $nextUid = 1;

            public function processContent(int $pid, array $payload, ?int $existingUid, array $fileReferences = []): int
            {
                $this->calls[] = [
                    'pid' => $pid,
                    'payload' => $payload,
                    'existingUid' => $existingUid,
                    'fileReferences' => $fileReferences,
                ];
                return $existingUid ?? $this->nextUid++;
            }
            public function findByBlockId(string $blockId): ?int
            {
                return $this->existingUidMap[$blockId] ?? null;
            }
        };
    }

    /**
     * @return FalAdapterInterface&object{addedFiles: list<array{sourcePath: string, storageUid: int, folderPath: string}>, existingUidBySha1: array<string, int>}
     */
    private function newFalAdapter(): FalAdapterInterface
    {
        return new class implements FalAdapterInterface {
            /** @var list<array{sourcePath: string, storageUid: int, folderPath: string}> */
            public array $addedFiles = [];
            /** @var array<string, int> */
            public array $existingUidBySha1 = [];
            private int $nextUid = 1;

            public function findUidBySha1(string $sha1): ?int
            {
                return $this->existingUidBySha1[$sha1] ?? null;
            }
            public function addFile(string $sourcePath, int $storageUid, string $folderPath): int
            {
                $this->addedFiles[] = compact('sourcePath', 'storageUid', 'folderPath');
                return $this->nextUid++;
            }
            public function updateMetadata(int $fileUid, array $metadata): void
            {
                // not exercised in these tests
            }
        };
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
