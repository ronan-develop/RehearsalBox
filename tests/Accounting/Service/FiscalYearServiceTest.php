<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearChangedException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Security\Exception\AccessDeniedException;
use PHPUnit\Framework\Attributes\Test;

/** #362 : cycle de vie d'un exercice (ouvert, clôturé, approuvé), réservé aux administrateurs actifs, sans jamais de retour en arrière. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class FiscalYearServiceTest extends AccountingTestCase
{
    #[Test]
    public function testOnlyActiveAdministratorsCanDoAnything(): void
    {
        $id = $this->openYear();
        $calls = [
            fn ($u) => $this->years->list($u),
            fn ($u) => $this->years->get($u, $id),
            fn ($u) => $this->years->summary($u, $id),
            fn ($u) => $this->years->create($u, ['label' => 'X', 'startsOn' => '2030-01-01', 'endsOn' => '2030-12-31']),
            fn ($u) => $this->years->close($u, $id, 0),
            fn ($u) => $this->years->approve($u, $id, ['approvedOn' => '2026-12-10']),
        ];

        foreach ([$this->musician, $this->gone] as $actor) {
            foreach ($calls as $call) {
                try {
                    $call($actor);
                    self::fail('Accès refusé attendu');
                } catch (AccessDeniedException) {
                    self::addToAssertionCount(1);
                }
            }
        }
        self::assertSame(FiscalYearStatus::Ouvert, $this->years->get($this->admin, $id)->status, 'rien n\'a bougé');
    }

    #[Test]
    public function testTheFirstYearTakesTheOpeningBalanceFromTheInput(): void
    {
        $year = $this->years->get($this->admin, $this->openYear(opening: '350,00'));

        self::assertSame(35000, $year->openingBalanceCents);
        self::assertSame(FiscalYearStatus::Ouvert, $year->status);
        self::assertSame(1, $year->nextEntryNumber);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badYears(): iterable
    {
        yield 'nom vide' => [['label' => ''], 'label'];
        yield 'nom trop long' => [['label' => str_repeat('a', 61)], 'label'];
        yield 'nom sur deux lignes' => [['label' => "a\nb"], 'label'];
        yield 'début absent' => [['startsOn' => ''], 'startsOn'];
        yield 'fin avant le début' => [['startsOn' => '2026-10-01', 'endsOn' => '2025-10-01'], 'endsOn'];
        yield 'fin le jour du début' => [['startsOn' => '2025-10-01', 'endsOn' => '2025-10-01'], 'endsOn'];
        yield 'plus de 24 mois' => [['startsOn' => '2024-01-01', 'endsOn' => '2026-06-30'], 'endsOn'];
        yield 'solde d\'ouverture négatif' => [['openingBalance' => '-5'], 'openingBalance'];
        yield 'solde d\'ouverture flottant' => [['openingBalance' => 12.5], 'openingBalance'];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('badYears')]
    public function testAnInvalidYearIsRefusedWithItsFieldAndNothingIsWritten(array $override, string $field): void
    {
        try {
            $this->years->create($this->admin, $override + ['label' => '2025-2026', 'startsOn' => '2025-10-01', 'endsOn' => '2026-09-30', 'openingBalance' => '0'], $this->clock);
            self::fail('Exercice refusé attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
        self::assertSame([], $this->years->list($this->admin));
    }

    #[Test]
    public function testTheNextYearMustFollowTheClosedPreviousOneAndTheNameIsUnique(): void
    {
        $first = $this->openYear();

        try {
            $this->openYear('2026-2027', '2026-10-01', '2027-09-30');
            self::fail('L\'exercice précédent doit être clos');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('startsOn', $e->fields());
        }

        $this->record($first);
        $this->years->close($this->admin, $first, 0, $this->clock);
        try {
            $this->openYear('2025-2026', '2026-10-01', '2027-09-30');
            self::fail('Nom déjà pris');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('label', $e->fields());
        }
        $second = $this->years->get($this->admin, $this->openYear('2026-2027', '2026-10-01', '2027-09-30', '999,00'));

        self::assertSame(12000, $second->openingBalanceCents, 'report calculé (100 € + 20 €), le solde saisi est ignoré');
        self::assertCount(2, $this->years->list($this->admin));
    }

    #[Test]
    public function testYearsMustBeContiguousAndNeverOverlap(): void
    {
        $first = $this->openYear();
        $this->years->close($this->admin, $first, 0, $this->clock);

        foreach ([['2026-09-01', '2027-08-31'], ['2026-10-02', '2027-09-30'], ['2027-01-01', '2027-12-31'], ['2024-10-01', '2025-09-30']] as [$start, $end]) {
            try {
                $this->years->create($this->admin, ['label' => 'X' . $start, 'startsOn' => $start, 'endsOn' => $end], $this->clock);
                self::fail("{$start} aurait dû être refusé");
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('startsOn', $e->fields());
            }
        }
        self::assertCount(1, $this->years->list($this->admin));
    }

    #[Test]
    public function testClosingFreezesTheBalanceTheStatusAndTheAuthor(): void
    {
        $id = $this->openYear();
        $this->record($id);
        $this->expense($id, '30,00');
        $revision = $this->years->get($this->admin, $id)->revision;

        $this->years->close($this->admin, $id, $revision, $this->clock);

        $year = $this->years->get($this->admin, $id);
        self::assertSame(FiscalYearStatus::Cloture, $year->status);
        self::assertSame(9000, $year->closingBalanceCents, '100 € + 20 € − 30 €');
        self::assertSame($revision + 1, $year->revision);
        $row = $this->pdo->query("SELECT closed_by_user_id, closed_at FROM accounting_fiscal_years WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($this->admin->id(), (int) $row['closed_by_user_id']);
        self::assertSame('2026-10-04 12:00:00', $row['closed_at']);
    }

    #[Test]
    public function testClosingTwiceOrWithAStaleRevisionChangesNothing(): void
    {
        $id = $this->openYear();
        $revision = $this->years->get($this->admin, $id)->revision;

        try {
            $this->years->close($this->admin, $id, $revision + 5, $this->clock);
            self::fail('Révision périmée');
        } catch (FiscalYearChangedException) {
            self::assertSame(FiscalYearStatus::Ouvert, $this->years->get($this->admin, $id)->status);
        }
        $this->years->close($this->admin, $id, $revision, $this->clock);
        $this->expectException(FiscalYearLockedException::class);
        $this->years->close($this->admin, $id, $revision + 1, $this->clock);
    }

    #[Test]
    public function testApprovalNeedsAClosedYearAndAValidDateAndIsFinal(): void
    {
        $id = $this->openYear();

        try {
            $this->years->approve($this->admin, $id, ['approvedOn' => '2026-12-10'], $this->clock);
            self::fail('Un exercice ouvert ne s\'approuve pas');
        } catch (FiscalYearLockedException) {
            self::addToAssertionCount(1);
        }
        $this->years->close($this->admin, $id, 0, $this->clock);
        foreach (['2026-09-29', '2026-10-05', '', 'hier'] as $bad) {
            try {
                $this->years->approve($this->admin, $id, ['approvedOn' => $bad], $this->clock);
                self::fail("« {$bad} » : date refusée attendue (avant la fin de l'exercice, dans le futur ou invalide)");
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('approvedOn', $e->fields());
            }
        }
        $this->years->approve($this->admin, $id, ['approvedOn' => '2026-10-04'], $this->clock);

        $year = $this->years->get($this->admin, $id);
        self::assertSame(FiscalYearStatus::Approuve, $year->status);
        self::assertSame('2026-10-04', $year->approvedOn?->format('Y-m-d'));
        $this->expectException(FiscalYearLockedException::class);
        $this->years->approve($this->admin, $id, ['approvedOn' => '2026-10-04'], $this->clock);
    }

    #[Test]
    public function testUnknownYearsAreNotFoundWithoutRepeatingTheIdentifier(): void
    {
        foreach ([fn () => $this->years->get($this->admin, 999999), fn () => $this->years->summary($this->admin, 999999), fn () => $this->years->close($this->admin, 999999, 0)] as $call) {
            try {
                $call();
                self::fail('Exercice introuvable attendu');
            } catch (FiscalYearNotFoundException $e) {
                self::assertStringNotContainsString('999999', $e->getMessage());
            }
        }
    }

    #[Test]
    public function testTheSummaryComputesTheBalanceAndTheNetByCategoryAtReadTime(): void
    {
        $id = $this->openYear();
        $this->record($id);
        $this->record($id, ['amount' => '20,00']);
        $this->expense($id, '10,50');

        $summary = $this->years->summary($this->admin, $id);

        self::assertSame(4000, $summary['totals']->recettesCents);
        self::assertSame(1050, $summary['totals']->depensesCents);
        self::assertSame(4000, $summary['totals']->netByCategory['cotisations']);
        self::assertSame(-1050, $summary['totals']->netByCategory['loyer']);
        self::assertSame(10000 + 4000 - 1050, $summary['balanceCents']);
    }

    #[Test]
    public function testTheJournalCarriesTheAuthorOfEverySensitiveActionButNeverTheContent(): void
    {
        $id = $this->openYear();
        $this->record($id, ['label' => 'Libellé très secret']);
        $this->years->close($this->admin, $id, 0, $this->clock);

        $log = implode("\n", $this->logged);
        self::assertStringContainsString('"actor":' . $this->admin->id(), $log);
        self::assertStringNotContainsString('secret', $log);
        self::assertStringNotContainsString('2000', $log, 'aucun montant dans le journal');
    }
}
