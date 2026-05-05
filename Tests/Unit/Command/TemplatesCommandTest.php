<?php

declare(strict_types=1);

namespace T3x\StaticHtmlImporter\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use T3x\StaticHtmlImporter\Command\TemplatesCommand;
use T3x\StaticHtmlImporter\Service\Ai\AiClassifierMock;
use T3x\StaticHtmlImporter\Service\Analyzer\BlockHasher;
use T3x\StaticHtmlImporter\Service\Analyzer\StructuralAnalyzer;
use Symfony\Component\HttpClient\MockHttpClient;
use T3x\StaticHtmlImporter\Service\Source\CrawlAdapter;
use T3x\StaticHtmlImporter\Service\Source\LocalFilesAdapter;
use T3x\StaticHtmlImporter\Service\Source\PatternLibraryAdapter;
use T3x\StaticHtmlImporter\Service\Source\SourceAdapterRegistry;
use T3x\StaticHtmlImporter\Service\Template\FluidPartialGenerator;

final class TemplatesCommandTest extends TestCase
{
    private string $sourceDir;
    private string $targetDir;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $unique = uniqid('', true);
        $this->sourceDir = sys_get_temp_dir() . '/t3shi-tplcmd-src-' . $unique;
        $this->targetDir = sys_get_temp_dir() . '/t3shi-tplcmd-tgt-' . $unique;
        mkdir($this->sourceDir, 0o755, true);
        mkdir($this->targetDir, 0o755, true);

        file_put_contents(
            $this->sourceDir . '/index.html',
            '<html><body><section data-component="hero"><h1>Welcome</h1><p>Lead</p></section></body></html>',
        );

        $registry = new SourceAdapterRegistry(
            new LocalFilesAdapter(),
            new CrawlAdapter(httpClient: new MockHttpClient([])),
            new PatternLibraryAdapter(),
        );
        $command = new TemplatesCommand(
            $registry,
            new StructuralAnalyzer(new BlockHasher()),
            new AiClassifierMock(),
            new FluidPartialGenerator(),
        );
        $command->setName('t3:static-html:templates');
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sourceDir);
        $this->rmrf($this->targetDir);
    }

    public function testGeneratesTemplatesForSource(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            '--target' => $this->targetDir,
        ]);

        $display = $this->tester->getDisplay();
        self::assertSame(0, $this->tester->getStatusCode(), $display);
        self::assertStringContainsString('Wrote (', $display);
        self::assertFileExists($this->targetDir . '/Layouts/Default.html');
        self::assertFileExists($this->targetDir . '/Partials/Generated/.manifest.json');
    }

    public function testDryRunReportsButDoesNotWrite(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            '--target' => $this->targetDir,
            '--dry-run' => true,
        ]);

        $display = $this->tester->getDisplay();
        self::assertSame(0, $this->tester->getStatusCode(), $display);
        self::assertStringContainsString('dry-run', $display);
        self::assertStringContainsString('Would write (', $display);
        self::assertFileDoesNotExist($this->targetDir . '/Layouts/Default.html');
    }

    public function testRejectsThresholdOutOfRange(): void
    {
        $this->tester->execute([
            'source' => $this->sourceDir,
            '--target' => $this->targetDir,
            '--threshold' => '1.5',
        ]);

        self::assertSame(2, $this->tester->getStatusCode());
        self::assertStringContainsString('--threshold must be in', $this->tester->getDisplay());
    }

    public function testFailsCleanlyOnMissingSource(): void
    {
        $this->tester->execute([
            'source' => '/nonexistent/path',
            '--target' => $this->targetDir,
        ]);

        self::assertSame(1, $this->tester->getStatusCode());
    }

    public function testIsIdempotentOnSecondRun(): void
    {
        $this->tester->execute(['source' => $this->sourceDir, '--target' => $this->targetDir]);
        $this->tester->execute(['source' => $this->sourceDir, '--target' => $this->targetDir]);

        $display = $this->tester->getDisplay();
        self::assertStringContainsString('All targets already up to date.', $display);
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
