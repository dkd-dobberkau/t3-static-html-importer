<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Import;

use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Domain\Model\ImportMapping;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;
use T3x\StaticHtmlImporter\Service\Import\ContentImporter;
use T3x\StaticHtmlImporter\Service\Import\DataHandlerAdapterInterface;
use T3x\StaticHtmlImporter\Service\Mapping\FieldTransformer;

final class ContentImporterTest extends TestCase
{
    public function testBuildsPayloadWithCTypeAndDedupeColumn(): void
    {
        $importer = $this->makeImporter($this->newAdapter());

        $block = $this->block('hash-1234567890', '<section><h1>Hello</h1><p>Body</p></section>');
        $mapping = new ImportMapping(cType: 'textmedia');

        $payload = $importer->buildPayload($block, $mapping);

        self::assertSame('textmedia', $payload['CType']);
        self::assertSame('hash-1234567890', $payload[ContentImporter::DEDUPE_COLUMN]);
    }

    public function testRunsFieldsThroughTransformer(): void
    {
        $importer = $this->makeImporter($this->newAdapter());

        $block = $this->block('hash-1', '<section><h1>The Header</h1><p>The body</p></section>');
        $mapping = new ImportMapping(
            cType: 'textmedia',
            fields: [
                'header' => new FieldDefinition(name: 'header', description: 'h', type: 'string'),
                'bodytext' => new FieldDefinition(name: 'bodytext', description: 'b', type: 'string'),
            ],
        );

        $payload = $importer->buildPayload($block, $mapping);

        self::assertSame('The Header', $payload['header']);
        self::assertSame('The body', $payload['bodytext']);
    }

    public function testOmitsFieldsWhereTransformerReturnsNull(): void
    {
        $importer = $this->makeImporter($this->newAdapter());

        $block = $this->block('hash-1', '<section><p>Body only</p></section>');
        $mapping = new ImportMapping(
            cType: 'text',
            fields: [
                'header' => new FieldDefinition(name: 'header', description: 'h', type: 'string'),
            ],
        );

        $payload = $importer->buildPayload($block, $mapping);

        // Mock AiClassifier returns null when there is no <!--header:--> marker
        // and no h1-h3, so the field should be omitted entirely.
        self::assertArrayNotHasKey('header', $payload);
    }

    public function testInsertsWhenNoExistingRecord(): void
    {
        $adapter = $this->newAdapter();
        $importer = $this->makeImporter($adapter);

        $block = $this->block('hash-new', '<section><h1>Hi</h1></section>');
        $mapping = new ImportMapping(cType: 'text');

        $uid = $importer->import($block, $mapping, targetPid: 42);

        self::assertSame(1, $uid);
        self::assertCount(1, $adapter->calls);
        self::assertSame(42, $adapter->calls[0]['pid']);
        self::assertNull($adapter->calls[0]['existingUid']);
    }

    public function testUpdatesExistingRecordWhenAdapterFindsOne(): void
    {
        $adapter = $this->newAdapter();
        $adapter->existingUidMap['hash-known'] = 99;
        $importer = $this->makeImporter($adapter);

        $block = $this->block('hash-known', '<section><h1>Hi</h1></section>');
        $mapping = new ImportMapping(cType: 'text');

        $uid = $importer->import($block, $mapping, targetPid: 1);

        self::assertSame(99, $uid, 'second run must reuse existing uid');
        self::assertSame(99, $adapter->calls[0]['existingUid']);
    }

    public function testForwardsTargetPid(): void
    {
        $adapter = $this->newAdapter();
        $importer = $this->makeImporter($adapter);

        $importer->import(
            $this->block('hash-x', '<section><p>x</p></section>'),
            new ImportMapping(cType: 'text'),
            targetPid: 7,
        );

        self::assertSame(7, $adapter->calls[0]['pid']);
    }

    private function makeImporter(DataHandlerAdapterInterface $adapter): ContentImporter
    {
        return new ContentImporter(
            fieldTransformer: new FieldTransformer(new AiClassifierMock()),
            adapter: $adapter,
        );
    }

    /**
     * @return DataHandlerAdapterInterface&object{calls: list<array{pid: int, payload: array<string, mixed>, existingUid: ?int}>, existingUidMap: array<string, int>}
     */
    private function newAdapter(): DataHandlerAdapterInterface
    {
        return new class implements DataHandlerAdapterInterface {
            /** @var list<array{pid: int, payload: array<string, mixed>, existingUid: ?int}> */
            public array $calls = [];

            /** @var array<string, int> */
            public array $existingUidMap = [];

            private int $nextUid = 1;

            public function processContent(int $pid, array $payload, ?int $existingUid): int
            {
                $this->calls[] = ['pid' => $pid, 'payload' => $payload, 'existingUid' => $existingUid];
                return $existingUid ?? $this->nextUid++;
            }

            public function findByBlockId(string $blockId): ?int
            {
                return $this->existingUidMap[$blockId] ?? null;
            }
        };
    }

    private function block(string $id, string $html): ContentBlock
    {
        return new ContentBlock(
            id: $id,
            html: $html,
            tag: 'section',
            candidateTypes: ['text'],
            confidence: 0.5,
        );
    }
}
