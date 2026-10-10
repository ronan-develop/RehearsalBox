<?php

declare(strict_types=1);

namespace App\Tests\Tools;

use App\Tools\CoverageSummary;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #129 : résumé de la couverture de tests par domaine, par couche et au total, lu dans le rapport Clover de PHPUnit. */
final class CoverageSummaryTest extends TestCase
{
    private const SRC = '/app/src';

    /** @param array<string, array{int, int}> $files chemin relatif à src/ => [instructions, couvertes] */
    private function clover(array $files, string $outside = ''): string
    {
        $nodes = '';
        foreach ($files as $path => [$statements, $covered]) {
            $nodes .= sprintf('<file name="%s/%s"><metrics statements="%d" coveredstatements="%d"/></file>', self::SRC, $path, $statements, $covered);
        }

        return '<?xml version="1.0"?><coverage><project><package name="App">' . $nodes . $outside . '</package></project></coverage>';
    }

    #[Test]
    public function testGroupsByDomainAndLayerAndSumsTheStatements(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover([
            'Messaging/Service/A.php' => [10, 5],
            'Messaging/Service/Mention/B.php' => [10, 10],
            'Messaging/Repository/C.php' => [20, 0],
            'Group/Service/D.php' => [4, 2],
        ]), self::SRC);

        self::assertSame(['covered' => 15, 'statements' => 20], $summary['folders']['Messaging/Service']);
        self::assertSame(['covered' => 0, 'statements' => 20], $summary['folders']['Messaging/Repository']);
        self::assertSame(['covered' => 2, 'statements' => 4], $summary['folders']['Group/Service']);
    }

    #[Test]
    public function testAggregatesByLayerAcrossDomains(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover([
            'Messaging/Service/A.php' => [10, 5],
            'Group/Service/D.php' => [10, 10],
            'Group/Repository/E.php' => [10, 0],
        ]), self::SRC);

        self::assertSame(['covered' => 15, 'statements' => 20], $summary['layers']['Service']);
        self::assertSame(['covered' => 0, 'statements' => 10], $summary['layers']['Repository']);
    }

    #[Test]
    public function testTotalCountsEveryFileOfSrc(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover([
            'Messaging/Service/A.php' => [10, 5],
            'Kernel.php' => [30, 30],
        ]), self::SRC);

        self::assertSame(['covered' => 35, 'statements' => 40], $summary['total']);
        self::assertSame(['covered' => 30, 'statements' => 30], $summary['folders']['(racine)']);
    }

    #[Test]
    public function testAFileDirectlyInADomainFolderHasTheDomainAsItsKeyAndNoLayer(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover(['Dashboard/DashboardView.php' => [8, 4]]), self::SRC);

        self::assertSame(['covered' => 4, 'statements' => 8], $summary['folders']['Dashboard']);
        self::assertSame([], $summary['layers']);
    }

    #[Test]
    public function testFilesOutsideSrcAreIgnored(): void
    {
        $outside = '<file name="/app/vendor/x/Y.php"><metrics statements="100" coveredstatements="100"/></file>';

        $summary = (new CoverageSummary())->fromClover($this->clover(['Group/Service/D.php' => [4, 2]], $outside), self::SRC);

        self::assertSame(['covered' => 2, 'statements' => 4], $summary['total']);
    }

    #[Test]
    public function testAnEmptySrcGivesAZeroTotal(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover([]), self::SRC);

        self::assertSame(['covered' => 0, 'statements' => 0], $summary['total']);
        self::assertSame([], $summary['folders']);
    }

    #[Test]
    public function testAnUnreadableReportIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CoverageSummary())->fromClover('pas du xml', self::SRC);
    }

    #[Test]
    public function testMarkdownListsTheWeakestFoldersFirstWithPercentagesAndTheTotal(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover([
            'Messaging/Service/A.php' => [10, 9],
            'Messaging/Repository/C.php' => [20, 5],
            'Group/Entity/E.php' => [5, 0],
        ]), self::SRC);

        $markdown = (new CoverageSummary())->toMarkdown($summary);

        self::assertLessThan(strpos($markdown, 'Messaging/Repository'), strpos($markdown, 'Group/Entity'), 'le moins couvert d\'abord');
        self::assertLessThan(strpos($markdown, 'Messaging/Service'), strpos($markdown, 'Messaging/Repository'));
        self::assertStringContainsString('| Messaging/Service | 90,0 % | 9 / 10 |', $markdown);
        self::assertStringContainsString('| Group/Entity | 0,0 % | 0 / 5 |', $markdown);
        self::assertStringContainsString('**Total : 40,0 %** (14 / 35)', $markdown);
        self::assertStringContainsString('| Entity | 0,0 % | 0 / 5 |', $markdown, 'tableau par couche');
    }

    #[Test]
    public function testFoldersWithNothingToCoverAreLeftOutOfTheTables(): void
    {
        $summary = (new CoverageSummary())->fromClover($this->clover([
            'Messaging/Exception/NoCode.php' => [0, 0],
            'Group/Service/D.php' => [4, 2],
        ]), self::SRC);

        $markdown = (new CoverageSummary())->toMarkdown($summary);

        self::assertStringNotContainsString('Messaging/Exception', $markdown);
        self::assertStringContainsString('| Group/Service | 50,0 % | 2 / 4 |', $markdown);
    }

    #[Test]
    public function testMarkdownHandlesAnEmptyReport(): void
    {
        $markdown = (new CoverageSummary())->toMarkdown(['total' => ['covered' => 0, 'statements' => 0], 'folders' => [], 'layers' => []]);

        self::assertStringContainsString('**Total : 0,0 %** (0 / 0)', $markdown);
    }

    #[Test]
    public function testTheMinimumIsMetAboveAndExactlyAtTheThreshold(): void
    {
        $tool = new CoverageSummary();
        $above = $tool->fromClover($this->clover(['A/B/C.php' => [100, 95]]), self::SRC);
        $equal = $tool->fromClover($this->clover(['A/B/C.php' => [100, 90]]), self::SRC);

        self::assertNull($tool->minimumFailure($above, 90.0));
        self::assertNull($tool->minimumFailure($equal, 90.0), 'égal au seuil : accepté');
    }

    #[Test]
    public function testBelowTheMinimumTheMessageGivesTheTotalAndTheThreshold(): void
    {
        $tool = new CoverageSummary();
        $below = $tool->fromClover($this->clover(['A/B/C.php' => [1000, 899]]), self::SRC);

        $message = $tool->minimumFailure($below, 90.0);

        self::assertNotNull($message);
        self::assertStringContainsString('89,9 %', $message);
        self::assertStringContainsString('90,0 %', $message);
    }

    #[Test]
    public function testAnEmptyReportFailsAnyPositiveMinimumButNotZero(): void
    {
        $tool = new CoverageSummary();
        $empty = $tool->fromClover($this->clover([]), self::SRC);

        self::assertNotNull($tool->minimumFailure($empty, 90.0));
        self::assertNull($tool->minimumFailure($empty, 0.0));
    }

    #[Test]
    public function testTheMinimumOptionAcceptsPercentagesAndRefusesTheRest(): void
    {
        self::assertSame(90.0, CoverageSummary::parseMinimum('90'));
        self::assertSame(87.5, CoverageSummary::parseMinimum('87.5'));
        self::assertSame(0.0, CoverageSummary::parseMinimum('0'));
        self::assertSame(100.0, CoverageSummary::parseMinimum('100'));

        foreach (['', 'abc', '-1', '100.1', '9 0', '1e2', 'NaN'] as $invalid) {
            try {
                CoverageSummary::parseMinimum($invalid);
                self::fail("« {$invalid} » aurait dû être refusé.");
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
