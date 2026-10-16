<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Service\AccountingAmountParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : un montant saisi en euros devient des centimes ENTIERS ; tout ce qui n'est pas un montant clair est refusé (jamais de flottant). */
final class AccountingAmountParserTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: int}> */
    public static function valid(): iterable
    {
        yield 'entier' => ['12', 1200];
        yield 'virgule française' => ['12,50', 1250];
        yield 'point' => ['12.50', 1250];
        yield 'un seul chiffre après la virgule' => ['12,5', 1250];
        yield 'un centime' => ['0,01', 1];
        yield 'espaces autour' => ['  468,00  ', 46800];
        yield 'zéro avant la virgule omis' => ['468', 46800];
        yield 'plafond' => ['999999999,99', 99999999999];
    }

    #[Test]
    #[DataProvider('valid')]
    public function testAClearAmountBecomesExactCents(string $input, int $cents): void
    {
        self::assertSame($cents, (new AccountingAmountParser())->parse($input));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalid(): iterable
    {
        foreach (['', ' ', '0', '0,00', '-5', '-0', '+5', '1e3', '0x10', '12.505', '12,505', '1 234,50', '1,234.50', '12,', ',50', '.5', '12..5', '12,5,5',
            'NaN', 'INF', 'abc', '12 €', '12€', "12\n", "1\x002", '٣٤', '１２', '1000000000', '999999999,999', str_repeat('9', 30), '９'] as $value) {
            yield 'texte « ' . addcslashes((string) $value, "\0..\37") . ' »' => [$value];
        }
        yield 'entier PHP' => [12];
        yield 'flottant PHP' => [12.5];
        yield 'nul' => [null];
        yield 'booléen' => [true];
        yield 'tableau' => [['12']];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function testAnythingElseIsRefused(mixed $input): void
    {
        self::assertNull((new AccountingAmountParser())->parse($input));
    }

    #[Test]
    public function testZeroIsAllowedOnlyWhenAskedFor(): void
    {
        $parser = new AccountingAmountParser();

        self::assertSame(0, $parser->parse('0,00', true));
        self::assertSame(0, $parser->parse('0', true));
        self::assertNull($parser->parse('0,00'));
    }

    #[Test]
    public function testRandomInputNeverYieldsANonPositiveOrOversizedAmountNorAnError(): void
    {
        mt_srand(362);
        $alphabet = ['0', '1', '9', ',', '.', ' ', '-', '+', 'e', 'x', "\n", "\0", '٣', '€', 'a'];
        $parser = new AccountingAmountParser();
        for ($i = 0; $i < 3000; ++$i) {
            $text = '';
            for ($n = mt_rand(0, 14); $n > 0; --$n) {
                $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $cents = $parser->parse($text);
            self::assertTrue($cents === null || ($cents > 0 && $cents <= 99999999999), 'montant impossible pour « ' . addcslashes($text, "\0..\37") . ' »');
        }
    }
}
