<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Import\Parse;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Import\Parse\CsvProfile;
use App\Accounting\Import\Parse\ImportProfilePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : le profil de colonnes saisi à l'import ne contient que des valeurs de listes fermées et des entiers bornés. */
final class ImportProfilePolicyTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function input(array $override = []): array
    {
        return $override + ['delimiter' => 'semicolon', 'encoding' => 'UTF-8', 'skipRows' => '1', 'dateColumn' => '1', 'labelColumn' => '2', 'amountMode' => 'signed', 'amountColumn' => '3', 'decimalSeparator' => ',', 'dateFormat' => 'd/m/Y'];
    }

    #[Test]
    public function testASignedProfileIsBuiltFromNamedChoices(): void
    {
        $profile = (new ImportProfilePolicy())->parse($this->input());

        self::assertSame([';', 'UTF-8', 1, 1, 2, CsvProfile::SIGNED, 3, ',', 'd/m/Y'], [$profile->delimiter, $profile->encoding, $profile->skipRows, $profile->dateColumn, $profile->labelColumn, $profile->amountMode, $profile->amountColumn, $profile->decimalSeparator, $profile->dateFormat]);
    }

    #[Test]
    public function testASplitProfileNeedsDebitAndCreditColumns(): void
    {
        $profile = (new ImportProfilePolicy())->parse($this->input(['amountMode' => 'split', 'debitColumn' => 3, 'creditColumn' => 4, 'delimiter' => 'tab']));

        self::assertSame(["\t", 3, 4], [$profile->delimiter, $profile->debitColumn, $profile->creditColumn]);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'séparateur inconnu' => [['delimiter' => '|'], 'delimiter'];
        yield 'séparateur littéral' => [['delimiter' => ';'], 'delimiter'];
        yield 'encodage inconnu' => [['encoding' => 'UTF-16'], 'encoding'];
        yield 'mode inconnu' => [['amountMode' => 'autre'], 'amountMode'];
        yield 'décimale inconnue' => [['decimalSeparator' => ';'], 'decimalSeparator'];
        yield 'format de date libre' => [['dateFormat' => 'd/m/Y H:i'], 'dateFormat'];
        yield 'en-tête négatif' => [['skipRows' => '-1'], 'skipRows'];
        yield 'en-tête énorme' => [['skipRows' => '11'], 'skipRows'];
        yield 'colonne zéro' => [['dateColumn' => '0'], 'dateColumn'];
        yield 'colonne texte' => [['labelColumn' => 'B'], 'labelColumn'];
        yield 'colonne trop grande' => [['amountColumn' => '31'], 'amountColumn'];
        yield 'colonne avec zéro initial' => [['amountColumn' => '03'], 'amountColumn'];
        yield 'colonne tableau' => [['dateColumn' => ['1']], 'dateColumn'];
        yield 'colonnes en double' => [['labelColumn' => '1'], 'dateColumn'];
        yield 'débit manquant' => [['amountMode' => 'split', 'creditColumn' => '4'], 'debitColumn'];
        yield 'montant manquant' => [['amountColumn' => null], 'amountColumn'];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('invalid')]
    public function testAnInvalidChoiceIsReportedByName(array $override, string $field): void
    {
        try {
            (new ImportProfilePolicy())->parse($this->input($override));
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
    }

    #[Test]
    public function testAllErrorsComeBackTogether(): void
    {
        try {
            (new ImportProfilePolicy())->parse([]);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertGreaterThanOrEqual(6, count($e->fields()));
        }
    }
}
