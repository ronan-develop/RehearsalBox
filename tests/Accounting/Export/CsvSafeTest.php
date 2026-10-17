<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Export;

use App\Accounting\Export\CsvSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : une cellule d'export ne doit jamais être lue comme une formule par un tableur (=, +, -, @, tabulation, retour chariot, même après des espaces). */
final class CsvSafeTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function dangerous(): iterable
    {
        yield 'égal' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+33612345678', "'+33612345678"];
        yield 'moins' => ['-2+3', "'-2+3"];
        yield 'arobase' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tabulation' => ["\t=1", "'\t=1"];
        yield 'retour chariot' => ["\r=1", "'\r=1"];
        yield 'saut de ligne' => ["\n=1", "'\n=1"];
        yield 'espaces puis égal' => ['   =1+1', "'   =1+1"];
        yield 'espace insécable puis égal' => ["\u{a0}=1", "'\u{a0}=1"];
        yield 'DDE' => ['=cmd|\' /C calc\'!A0', "'=cmd|' /C calc'!A0"];
        yield 'hyperlien' => ['=HYPERLINK("http://x.test","a")', "'=HYPERLINK(\"http://x.test\",\"a\")"];
    }

    #[Test]
    #[DataProvider('dangerous')]
    public function testADangerousStartIsNeutralised(string $input, string $expected): void
    {
        self::assertSame($expected, CsvSafe::cell($input));
    }

    /** @return iterable<string, array{0: string}> */
    public static function harmless(): iterable
    {
        yield 'texte' => ['Cotisation'];
        yield 'vide' => [''];
        yield 'accents' => ['Électricité de novembre'];
        yield 'signe au milieu' => ['Loyer = 166 €'];
        yield 'tiret au milieu' => ['Loyer - octobre'];
        yield 'point-virgule' => ['a;b'];
    }

    #[Test]
    #[DataProvider('harmless')]
    public function testAnOrdinaryTextIsUntouched(string $input): void
    {
        self::assertSame($input, CsvSafe::cell($input));
    }
}
