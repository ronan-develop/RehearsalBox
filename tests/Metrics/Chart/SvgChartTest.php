<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Chart;

use App\Metrics\Chart\SvgChart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SvgChartTest extends TestCase
{
    #[Test]
    public function testBarsAreAccessibleWithATitleASummaryAndAValuesTable(): void
    {
        $html = (new SvgChart())->bars('Requêtes servies', 'requêtes', ['8 h', '9 h', '10 h'], [10, 40, 25]);

        self::assertStringContainsString('role="img"', $html);
        self::assertStringContainsString('<title id="rb-chart-1-t">Requêtes servies</title>', $html);
        self::assertStringContainsString('3 points, maximum 40 requêtes, total 75 requêtes.', $html);
        self::assertSame(3, substr_count($html, 'class="rb-chart-bar'));
        self::assertStringContainsString('<summary>Tableau des valeurs</summary>', $html);
        self::assertStringContainsString('<th scope="row">9 h</th><td>40</td>', $html);
    }

    #[Test]
    public function testTheAxisUsesRoundNumbers(): void
    {
        $html = (new SvgChart())->bars('T', 'u', ['a', 'b'], [3, 87]);

        self::assertMatchesRegularExpression('#>100</text>#', $html);
        self::assertMatchesRegularExpression('#>50</text>#', $html);
    }

    #[Test]
    public function testEveryTextIsEscaped(): void
    {
        $html = (new SvgChart())->bars('<script>alert(1)</script>', 'u"', ['<b>', 'a'], [1, 2]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function testWithoutAnyValueTheChartSaysSoAndDrawsNoMark(): void
    {
        $empty = (new SvgChart())->bars('Vide', 'u', [], []);
        $zeros = (new SvgChart())->bars('Zéros', 'u', ['a', 'b'], [0, 0]);

        foreach ([$empty, $zeros] as $html) {
            self::assertStringContainsString('Aucune donnée sur la période.', $html);
            self::assertStringNotContainsString('class="rb-chart-bar', $html);
            self::assertStringNotContainsString('NAN', $html);
            self::assertStringNotContainsString('INF', $html);
        }
    }

    #[Test]
    public function testASingleValueIsADotAndNotABrokenLine(): void
    {
        $html = (new SvgChart())->line('Un seul', 'ms', ['10 h'], [42]);

        self::assertStringContainsString('class="rb-chart-dot"', $html);
        self::assertStringNotContainsString('<polyline', $html);
    }

    #[Test]
    public function testALineIsDrawnWithItsEndDot(): void
    {
        $html = (new SvgChart())->line('Courbe', 'ms', ['a', 'b', 'c'], [1, 5, 3]);

        self::assertStringContainsString('<polyline class="rb-chart-line"', $html);
        self::assertSame(1, substr_count($html, 'rb-chart-dot'));
    }

    #[Test]
    public function testAnErrorToneUsesItsOwnClassAndLongSeriesKeepFewAxisLabels(): void
    {
        $labels = array_map(static fn (int $i): string => 'L' . $i, range(0, 29));
        $html = (new SvgChart())->bars('Erreurs', 'u', $labels, range(0, 29), 'err');

        self::assertStringContainsString('rb-chart-bar--err', $html);
        self::assertLessThanOrEqual(5, preg_match_all('#class="rb-chart-tick" x="[\d.]+" y="1[67]\d\.\d" text-anchor="middle"#', $html));
        self::assertStringContainsString('>L0</text>', $html);
        self::assertStringContainsString('>L29</text>', $html);
    }

    #[Test]
    public function testTwoChartsOnAPageNeverShareAnId(): void
    {
        $chart = new SvgChart();

        self::assertStringContainsString('rb-chart-1', $chart->bars('A', 'u', ['a'], [1]));
        self::assertStringContainsString('rb-chart-2', $chart->bars('B', 'u', ['a'], [1]));
    }
}
