<?php

declare(strict_types=1);

namespace App\Tests\Bin;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #365 : `bin/coverage-summary.php --min=N` échoue (code 1) sous le seuil, réussit au-dessus ou à égalité, et affiche toujours le résumé. */
final class CoverageSummaryScriptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/coverage-script-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function report(int $statements, int $covered): string
    {
        $src = (string) realpath(__DIR__ . '/../../src');
        $path = $this->dir . '/clover.xml';
        file_put_contents($path, sprintf('<?xml version="1.0"?><coverage><project><package name="App"><file name="%s/Demo/Service/A.php"><metrics statements="%d" coveredstatements="%d"/></file></package></project></coverage>', $src, $statements, $covered));

        return $path;
    }

    /** @return array{int, string} code de sortie et sortie (standard + erreur) */
    private function runScript(string ...$arguments): array
    {
        $command = 'php ' . escapeshellarg(__DIR__ . '/../../bin/coverage-summary.php') . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    #[Test]
    public function testWithoutMinimumTheScriptOnlyReports(): void
    {
        [$code, $output] = $this->runScript($this->report(100, 10));

        self::assertSame(0, $code);
        self::assertStringContainsString('Total : 10,0 %', $output);
    }

    #[Test]
    public function testAboveOrEqualToTheMinimumTheScriptSucceedsAndStillShowsTheSummary(): void
    {
        foreach ([95, 90] as $covered) {
            [$code, $output] = $this->runScript($this->report(100, $covered), '--min=90');

            self::assertSame(0, $code);
            self::assertStringContainsString('Total :', $output);
        }
    }

    #[Test]
    public function testBelowTheMinimumTheScriptFailsWithAClearMessageAndStillShowsTheSummary(): void
    {
        [$code, $output] = $this->runScript($this->report(100, 80), '--min=90');

        self::assertSame(1, $code);
        self::assertStringContainsString('Total : 80,0 %', $output);
        self::assertStringContainsString('inférieure au minimum', $output);
    }

    #[Test]
    public function testAnInvalidMinimumIsRefusedBeforeAnything(): void
    {
        foreach (['--min=abc', '--min=150', '--min='] as $option) {
            [$code, $output] = $this->runScript($this->report(100, 100), $option);

            self::assertSame(1, $code);
            self::assertStringNotContainsString('Total :', $output);
        }
    }

    #[Test]
    public function testAMissingReportFailsEvenWithAMinimum(): void
    {
        [$code, $output] = $this->runScript($this->dir . '/absent.xml', '--min=90');

        self::assertSame(1, $code);
        self::assertStringContainsString('introuvable', $output);
    }
}
