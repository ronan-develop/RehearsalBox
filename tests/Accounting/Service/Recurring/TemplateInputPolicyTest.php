<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service\Recurring;

use App\Accounting\Entity\Periodicity;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Service\AccountingAmountParser;
use App\Accounting\Service\Recurring\TemplateInputPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : saisie d'un modèle récurrent : montant fixe ou variable, rythme, jour 1 à 28, premier mois, cohérence sens/catégorie. */
final class TemplateInputPolicyTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function input(array $override = []): array
    {
        return $override + ['label' => 'Loyer du local', 'direction' => 'depense', 'category' => 'loyer', 'paymentMethod' => 'virement', 'amount' => '498,00', 'voucherRef' => 'Virement loyer', 'periodicity' => 'trimestrielle', 'dueDay' => '5', 'firstMonth' => '2025-10'];
    }

    private function parse(array $input): \App\Accounting\Service\Recurring\TemplateDraft
    {
        return (new TemplateInputPolicy(new AccountingAmountParser()))->parse($input);
    }

    #[Test]
    public function testAFixedTemplateIsReadInCentsAndAVariableOneHasNoAmount(): void
    {
        $fixed = $this->parse($this->input());
        self::assertSame(49800, $fixed->amountCents);
        self::assertSame(Periodicity::Trimestrielle, $fixed->periodicity);
        self::assertSame(5, $fixed->dueDay);
        self::assertSame('2025-10-01', $fixed->firstMonth->format('Y-m-d'));
        self::assertTrue($fixed->isActive);

        foreach (['', null] as $empty) {
            self::assertNull($this->parse($this->input(['amount' => $empty, 'category' => 'electricite']))->amountCents);
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'libellé vide' => [['label' => '  '], 'label'];
        yield 'libellé en tableau' => [['label' => ['x']], 'label'];
        yield 'sens inconnu' => [['direction' => 'autre'], 'direction'];
        yield 'catégorie d\'un autre sens' => [['category' => 'cotisations'], 'category'];
        yield 'moyen inconnu' => [['paymentMethod' => 'bitcoin'], 'paymentMethod'];
        yield 'périodicité inconnue' => [['periodicity' => 'hebdomadaire'], 'periodicity'];
        yield 'montant négatif' => [['amount' => '-5'], 'amount'];
        yield 'montant nul' => [['amount' => '0'], 'amount'];
        yield 'montant exposant' => [['amount' => '1e3'], 'amount'];
        yield 'jour 0' => [['dueDay' => '0'], 'dueDay'];
        yield 'jour 29' => [['dueDay' => '29'], 'dueDay'];
        yield 'jour 31' => [['dueDay' => 31], 'dueDay'];
        yield 'jour texte' => [['dueDay' => '5a'], 'dueDay'];
        yield 'jour zéro initial' => [['dueDay' => '05'], 'dueDay'];
        yield 'mois sans tiret' => [['firstMonth' => '202510'], 'firstMonth'];
        yield 'mois 13' => [['firstMonth' => '2025-13'], 'firstMonth'];
        yield 'mois avec jour' => [['firstMonth' => '2025-10-05'], 'firstMonth'];
        yield 'pièce trop longue' => [['voucherRef' => str_repeat('x', 101)], 'voucherRef'];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('invalid')]
    public function testAnInvalidFieldIsReportedByName(array $override, string $field): void
    {
        try {
            $this->parse($this->input($override));
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
    }

    #[Test]
    public function testAllErrorsComeBackTogether(): void
    {
        try {
            $this->parse(['label' => '', 'dueDay' => '99']);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertGreaterThanOrEqual(5, count($e->fields()));
        }
    }

    #[Test]
    public function testTheActiveFlagIsStrict(): void
    {
        self::assertFalse($this->parse($this->input(['isActive' => false]))->isActive);
        self::assertFalse($this->parse($this->input(['isActive' => 'oui']))->isActive);
        self::assertTrue($this->parse($this->input(['isActive' => '1']))->isActive);
    }
}
