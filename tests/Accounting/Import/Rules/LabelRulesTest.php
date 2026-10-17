<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Import\Rules;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Import\Rules\LabelNormalizer;
use App\Accounting\Import\Rules\LabelRule;
use App\Accounting\Import\Rules\LabelRuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : mémoire des libellés : normalisation, motif par défaut, règle la plus précise, doute = aucune règle. */
final class LabelRulesTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function normalizations(): iterable
    {
        yield 'casse et accents' => ['PRLV Électricité EDF', 'prlv electricite edf'];
        yield 'chiffres et ponctuation' => ['VIR SEPA 2025-11-05 REF:12345 LOYER', 'vir sepa ref loyer'];
        yield 'espaces multiples' => ["  A   B\tC ", 'a b c'];
        yield 'IBAN' => ['VIR FR76 3000 1234 5678 SECRET', 'vir fr secret'];
        yield 'vide' => ['', ''];
    }

    #[Test]
    #[DataProvider('normalizations')]
    public function testALabelIsNormalised(string $input, string $expected): void
    {
        self::assertSame($expected, LabelNormalizer::normalize($input));
    }

    #[Test]
    public function testTheDefaultPatternIsTheNormalisedLabelCutAtAWordAndNeverTooShort(): void
    {
        self::assertSame('prlv edf', LabelNormalizer::defaultPattern('PRLV EDF 12345'));
        self::assertNull(LabelNormalizer::defaultPattern('VIR 12'), 'trop court pour être sûr');
        $long = LabelNormalizer::defaultPattern(str_repeat('motmot ', 30));
        self::assertLessThanOrEqual(60, mb_strlen((string) $long));
        self::assertStringEndsWith('motmot', (string) $long);
    }

    private function rule(int $id, string $pattern, Direction $direction = Direction::Depense, EntryCategory $category = EntryCategory::Electricite, PaymentMethod $method = PaymentMethod::Virement): LabelRule
    {
        return new LabelRule($id, $pattern, $direction, $category, $method);
    }

    #[Test]
    public function testTheMostSpecificMatchingRuleWins(): void
    {
        $rules = [$this->rule(1, 'prlv', category: EntryCategory::AutresDepenses), $this->rule(2, 'prlv edf', category: EntryCategory::Electricite)];

        $match = (new LabelRuleMatcher())->match($rules, Direction::Depense, 'PRLV EDF CLIENT 123');

        self::assertSame(2, $match?->id);
    }

    #[Test]
    public function testARuleOfAnotherDirectionOrWithoutTheMotifDoesNotApply(): void
    {
        $matcher = new LabelRuleMatcher();
        $rules = [$this->rule(1, 'edf')];

        self::assertNull($matcher->match($rules, Direction::Recette, 'EDF'));
        self::assertNull($matcher->match($rules, Direction::Depense, 'AUTRE'));
        self::assertNull($matcher->match([], Direction::Depense, 'EDF'));
    }

    #[Test]
    public function testTwoContradictoryRulesOfTheSameLengthMeanDoubtAndNoRule(): void
    {
        $rules = [$this->rule(1, 'edf', category: EntryCategory::Electricite), $this->rule(2, 'edf', category: EntryCategory::AutresDepenses)];
        $matcher = new LabelRuleMatcher();

        self::assertNull($matcher->match($rules, Direction::Depense, 'PRLV EDF'));
        self::assertSame(1, $matcher->match([$this->rule(1, 'edf'), $this->rule(3, 'edf')], Direction::Depense, 'PRLV EDF')?->id, 'deux règles identiques ne sont pas un doute');
    }
}
