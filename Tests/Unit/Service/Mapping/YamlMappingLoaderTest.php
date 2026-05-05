<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Mapping;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use T3x\StaticHtmlImporter\Domain\Model\FieldDefinition;
use T3x\StaticHtmlImporter\Service\Mapping\YamlMappingLoader;

final class YamlMappingLoaderTest extends TestCase
{
    private string $dir;
    private YamlMappingLoader $loader;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/t3shi-mapping-' . uniqid('', true);
        mkdir($this->dir, 0o755, true);
        $this->loader = new YamlMappingLoader();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testLoadsValidFile(): void
    {
        $path = $this->write('m.yaml', <<<'YAML'
cType: textmedia
selector: 'section.card'
fields:
  header:
    description: 'The headline'
    type: string
YAML);

        $mapping = $this->loader->loadFile($path);

        self::assertSame('textmedia', $mapping->cType);
        self::assertSame('section.card', $mapping->selector);
        self::assertCount(1, $mapping->fields);
        self::assertInstanceOf(FieldDefinition::class, $mapping->fields['header']);
        self::assertSame('header', $mapping->fields['header']->name);
        self::assertSame('string', $mapping->fields['header']->type);
    }

    public function testDefaultsFieldTypeToString(): void
    {
        $path = $this->write('m.yaml', <<<'YAML'
cType: text
fields:
  header:
    description: 'h'
YAML);

        $mapping = $this->loader->loadFile($path);

        self::assertSame('string', $mapping->fields['header']->type);
    }

    public function testThrowsOnMissingCType(): void
    {
        $path = $this->write('m.yaml', "selector: foo\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cType');

        $this->loader->loadFile($path);
    }

    public function testThrowsOnInvalidFieldType(): void
    {
        $path = $this->write('m.yaml', <<<'YAML'
cType: t
fields:
  x:
    description: 'x'
    type: bogus
YAML);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Field type must be one of');

        $this->loader->loadFile($path);
    }

    public function testThrowsOnMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->loader->loadFile('/nonexistent/file.yaml');
    }

    public function testLoadsDirectoryIndexedByCType(): void
    {
        $this->write('a.yaml', "cType: text\n");
        $this->write('b.yaml', "cType: textmedia\n");

        $mappings = $this->loader->loadDirectory($this->dir);

        self::assertSame(['text', 'textmedia'], array_keys($mappings));
    }

    public function testThrowsOnDuplicateCType(): void
    {
        $this->write('a.yaml', "cType: text\n");
        $this->write('b.yaml', "cType: text\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate cType');

        $this->loader->loadDirectory($this->dir);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }
}
