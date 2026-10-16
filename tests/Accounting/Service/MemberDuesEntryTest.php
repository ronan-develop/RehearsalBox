<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Exception\AccountingValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/** #362 : cotisation PAR ADHÉRENT et par mois : champs réservés aux cotisations, compte actif, un seul paiement par mois (hors annulation), anonymisation. */
final class MemberDuesEntryTest extends AccountingTestCase
{
    /** @param array<string, mixed> $override */
    private function dues(int $year, array $override = []): \App\Accounting\Entity\AccountingEntry
    {
        return $this->record($year, $override + ['memberUserId' => (string) $this->musician->id(), 'periodMonth' => '2025-11', 'category' => 'cotisations']);
    }

    /** @param array<string, mixed> $override */
    private function refused(int $year, array $override, string $field): void
    {
        try {
            $this->dues($year, $override);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
    }

    #[Test]
    public function testADuesEntryNamesTheMemberAndTheMonthCovered(): void
    {
        $entry = $this->dues($this->openYear());

        self::assertSame($this->musician->id(), $entry->memberUserId);
        self::assertSame('2025-11-01', $entry->periodMonth?->format('Y-m-d'));
        self::assertSame($this->musician->id(), $this->entryRepository->find($entry->id)?->memberUserId);
    }

    #[Test]
    public function testTheSameMemberCannotPayTheSameMonthTwiceEvenInAnotherYear(): void
    {
        $year = $this->openYear();
        $this->dues($year);

        $this->refused($year, ['occurredOn' => '2025-11-20'], 'periodMonth');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
        self::assertSame(2, $this->yearRepository->find($year)?->nextEntryNumber, 'le refus ne consomme pas de numéro');
    }

    #[Test]
    public function testAnotherMemberOrAnotherMonthIsFree(): void
    {
        $year = $this->openYear();
        $other = (new \App\Account\Repository\MysqlUserRepository($this->pdo))->save(new \App\Account\Entity\User(0, 'autre@rehearsalbox.test', 'hash', 'Autre', \App\Account\Entity\UserRole::Musicien, true, 0, null));
        $this->dues($year);

        $this->dues($year, ['periodMonth' => '2025-12']);
        $this->dues($year, ['memberUserId' => (string) $other->id()]);

        self::assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    #[Test]
    public function testAReversedPaymentFreesTheMonthAgain(): void
    {
        $year = $this->openYear();
        $entry = $this->dues($year);
        $this->entries->reverse($this->admin, $entry->id, ['reason' => 'Erreur de saisie', 'occurredOn' => '2025-11-21'], $this->clock);

        $again = $this->dues($year, ['occurredOn' => '2025-11-22']);

        self::assertSame($this->musician->id(), $again->memberUserId);
        $this->refused($year, ['occurredOn' => '2025-11-23'], 'periodMonth');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalid(): iterable
    {
        yield 'mois sans adhérent' => [['memberUserId' => ''], 'memberUserId'];
        yield 'adhérent sans mois' => [['periodMonth' => ''], 'periodMonth'];
        yield 'adhérent texte' => [['memberUserId' => 'Jean'], 'memberUserId'];
        yield 'adhérent négatif' => [['memberUserId' => '-1'], 'memberUserId'];
        yield 'adhérent zéro' => [['memberUserId' => '0'], 'memberUserId'];
        yield 'adhérent exposant' => [['memberUserId' => '1e0'], 'memberUserId'];
        yield 'adhérent inconnu' => [['memberUserId' => '99999999'], 'memberUserId'];
        yield 'mois avec jour' => [['periodMonth' => '2025-11-05'], 'periodMonth'];
        yield 'mois 13' => [['periodMonth' => '2025-13'], 'periodMonth'];
        yield 'mois tableau' => [['periodMonth' => ['2025-11']], 'periodMonth'];
        yield 'hors cotisation' => [['category' => 'subventions'], 'memberUserId'];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('invalid')]
    public function testInvalidMemberFieldsAreRefusedByNameAndNothingIsWritten(array $override, string $field): void
    {
        $year = $this->openYear();

        $this->refused($year, $override, $field);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_entries')->fetchColumn());
    }

    #[Test]
    public function testADisabledAccountCannotBeCredited(): void
    {
        $this->refused($this->openYear(), ['memberUserId' => (string) $this->gone->id()], 'memberUserId');
    }

    #[Test]
    public function testADuesEntryWithoutMemberStaysPossible(): void
    {
        $entry = $this->record($this->openYear(), ['category' => 'cotisations']);

        self::assertNull($entry->memberUserId);
        self::assertNull($entry->periodMonth);
    }

    #[Test]
    public function testAnonymisingTheAccountLeavesTheEntryIntact(): void
    {
        $year = $this->openYear();
        $entry = $this->dues($year);
        $this->pdo->prepare("UPDATE users SET display_name = 'Ancien membre', email = 'ancien@invalid.test', is_active = 0 WHERE id = ?")->execute([$this->musician->id()]);

        $kept = $this->entryRepository->find($entry->id);

        self::assertSame($entry->amountCents, $kept?->amountCents);
        self::assertSame($this->musician->id(), $kept?->memberUserId);
    }
}
