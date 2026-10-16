<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Entity;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\PaymentMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : chaque valeur d'énumération a un libellé français affichable, sans doublon dans une même liste. */
final class AccountingLabelsTest extends TestCase
{
    /** @param list<\UnitEnum&object> $cases */
    private function assertDistinctLabels(array $cases): void
    {
        $labels = array_map(static fn ($case): string => $case->label(), $cases);
        foreach ($labels as $label) {
            self::assertNotSame('', trim($label));
        }
        self::assertSame($labels, array_values(array_unique($labels)), 'deux valeurs ne portent pas le même libellé');
    }

    #[Test]
    public function testEveryEnumValueHasADistinctFrenchLabel(): void
    {
        $this->assertDistinctLabels(Direction::cases());
        $this->assertDistinctLabels(EntryCategory::cases());
        $this->assertDistinctLabels(PaymentMethod::cases());
        $this->assertDistinctLabels(FiscalYearStatus::cases());
    }

    #[Test]
    public function testPlainWordsForPeopleWhoAreNotAccountants(): void
    {
        self::assertSame('Argent reçu', Direction::Recette->label());
        self::assertSame('Argent dépensé', Direction::Depense->label());
        self::assertSame('Cotisations', EntryCategory::Cotisations->label());
        self::assertSame('Loyer', EntryCategory::Loyer->label());
        self::assertSame('Virement', PaymentMethod::Virement->label());
        self::assertSame('Ouvert', FiscalYearStatus::Ouvert->label());
    }

    #[Test]
    public function testCategoriesAreListedPerDirectionForTheEntryForm(): void
    {
        $recettes = EntryCategory::forDirection(Direction::Recette);
        $depenses = EntryCategory::forDirection(Direction::Depense);

        self::assertContains(EntryCategory::Cotisations, $recettes);
        self::assertNotContains(EntryCategory::Loyer, $recettes);
        self::assertContains(EntryCategory::Loyer, $depenses);
        self::assertCount(count(EntryCategory::cases()), [...$recettes, ...$depenses]);
    }
}
