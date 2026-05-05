<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Service\Template;

use PHPUnit\Framework\TestCase;
use T3x\StaticHtmlImporter\Domain\Model\ContentBlock;
use T3x\StaticHtmlImporter\Service\Template\FluidPartialGenerator;

final class FluidPartialGeneratorTest extends TestCase
{
    private string $root;
    private FluidPartialGenerator $generator;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/t3shi-tpl-' . uniqid('', true);
        mkdir($this->root, 0o755, true);
        $this->generator = new FluidPartialGenerator();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->root);
    }

    public function testGeneratesOnePartialPerUniqueHash(): void
    {
        $a = $this->block('aaaa1111bbbb2222cccc', '<p>A</p>', ['textmedia']);
        $b = $this->block('bbbb1111cccc2222dddd', '<p>B</p>', ['text']);

        $written = $this->generator->generate([$a, $b], $this->root);

        self::assertContains('Partials/Generated/aaaa1111bbbb.html', $written);
        self::assertContains('Partials/Generated/bbbb1111cccc.html', $written);
    }

    public function testCollapsesIdenticalHashesIntoSinglePartial(): void
    {
        $a1 = $this->block('samehash000000000000', '<p>A1</p>', ['textmedia']);
        $a2 = $this->block('samehash000000000000', '<p>A2</p>', ['textmedia']);

        $written = $this->generator->generate([$a1, $a2], $this->root);

        $partials = array_values(array_filter(
            $written,
            static fn (string $f): bool => str_starts_with($f, 'Partials/'),
        ));
        self::assertSame(['Partials/Generated/samehash0000.html'], $partials);
    }

    public function testWritesPerCTypeTemplateThatRendersAllHashes(): void
    {
        $a = $this->block('hashA000000000000000', '<p>A</p>', ['textmedia']);
        $b = $this->block('hashB000000000000000', '<p>B</p>', ['textmedia']);
        $c = $this->block('hashC000000000000000', '<p>C</p>', ['text']);

        $written = $this->generator->generate([$a, $b, $c], $this->root);

        self::assertContains('Templates/textmedia.html', $written);
        self::assertContains('Templates/text.html', $written);

        $tmpl = file_get_contents($this->root . '/Templates/textmedia.html');
        self::assertNotFalse($tmpl);
        self::assertStringContainsString('hashA0000000', $tmpl);
        self::assertStringContainsString('hashB0000000', $tmpl);
        self::assertStringContainsString('<f:layout name="Default" />', $tmpl);
    }

    public function testWritesDefaultLayoutWhenMissing(): void
    {
        $written = $this->generator->generate([$this->block('h0', '<p>x</p>', ['t'])], $this->root);

        self::assertContains('Layouts/Default.html', $written);
        self::assertFileExists($this->root . '/Layouts/Default.html');
    }

    public function testDoesNotOverwriteExistingLayout(): void
    {
        mkdir($this->root . '/Layouts', 0o755, true);
        file_put_contents($this->root . '/Layouts/Default.html', 'CUSTOM');

        $written = $this->generator->generate([$this->block('h0', '<p>x</p>', ['t'])], $this->root);

        self::assertNotContains('Layouts/Default.html', $written);
        self::assertSame('CUSTOM', file_get_contents($this->root . '/Layouts/Default.html'));
    }

    public function testIsIdempotentOnSecondRun(): void
    {
        $a = $this->block('hashabc000000', '<p>A</p>', ['textmedia']);

        $first = $this->generator->generate([$a], $this->root);
        $second = $this->generator->generate([$a], $this->root);

        self::assertNotEmpty($first);
        self::assertSame([], $second);
    }

    public function testReWritesWhenTargetWasModifiedExternally(): void
    {
        $a = $this->block('hashabc000000', '<p>A</p>', ['textmedia']);
        $this->generator->generate([$a], $this->root);

        file_put_contents($this->root . '/Partials/Generated/hashabc00000.html', 'EDITED');

        $second = $this->generator->generate([$a], $this->root);

        self::assertContains('Partials/Generated/hashabc00000.html', $second);
    }

    public function testSanitisesNonAlphaNumericCharsInCTypeFilename(): void
    {
        $a = $this->block('h0', '<p>A</p>', ['role:contentinfo']);

        $written = $this->generator->generate([$a], $this->root);

        self::assertContains('Templates/role_contentinfo.html', $written);
    }

    public function testWritesManifestFile(): void
    {
        $a = $this->block('hashabc000000', '<p>A</p>', ['textmedia']);
        $this->generator->generate([$a], $this->root);

        $manifestPath = $this->root . '/Partials/Generated/.manifest.json';
        self::assertFileExists($manifestPath);

        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('Partials/Generated/hashabc00000.html', $manifest);
        self::assertArrayHasKey('Templates/textmedia.html', $manifest);
        self::assertArrayHasKey('Layouts/Default.html', $manifest);
    }

    /**
     * @param list<string> $candidates
     */
    private function block(string $id, string $html, array $candidates): ContentBlock
    {
        return new ContentBlock(
            id: $id,
            html: $html,
            tag: 'div',
            candidateTypes: $candidates,
            confidence: 0.5,
        );
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
