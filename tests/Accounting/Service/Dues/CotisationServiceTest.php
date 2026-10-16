<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service\Dues;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Repository\MysqlEntryRepository;
use App\Accounting\Repository\MysqlMemberDuesRepository;
use App\Accounting\Service\Dues\CotisationCell;
use App\Accounting\Service\Dues\CotisationService;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Accounting\Service\AccountingTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #362 : tableau des cotisations adhérents × mois calculé depuis le livre ; dispenses ; droits. */
final class CotisationServiceTest extends AccountingTestCase
{
    private CotisationService $dues;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dues = new CotisationService(new AdminAccess(), new MysqlEntryRepository($this->pdo), new MysqlMemberDuesRepository($this->pdo), new NullLogger());
        $this->today = new \DateTimeImmutable('2025-12-15');
    }

    private function pay(int $year, int $member, string $month, string $date = '2025-11-05'): \App\Accounting\Entity\AccountingEntry
    {
        return $this->record($year, ['category' => 'cotisations', 'memberUserId' => (string) $member, 'periodMonth' => $month, 'occurredOn' => $date]);
    }

    /** @return array<string, CotisationCell> */
    private function cellsOf(int $yearId, int $memberId): array
    {
        $table = $this->dues->table($this->admin, $this->yearRepository->find($yearId) ?? throw new \LogicException(), $this->today);
        foreach ($table->rows as $row) {
            if ($row['member']->id === $memberId) {
                return $row['cells'];
            }
        }
        self::fail('adhérent absent du tableau');
    }

    #[Test]
    public function testTheTableShowsPaidDueAndUpcomingMonthsFromTheLedger(): void
    {
        $year = $this->openYear();
        $this->pay($year, $this->musician->id(), '2025-11');

        $cells = $this->cellsOf($year, $this->musician->id());

        self::assertSame(CotisationCell::APayer, $cells['2025-10']);
        self::assertSame(CotisationCell::Payee, $cells['2025-11']);
        self::assertSame(CotisationCell::APayer, $cells['2025-12'], 'le mois courant est dû');
        self::assertSame(CotisationCell::AVenir, $cells['2026-01']);
        self::assertCount(12, $cells);
    }

    #[Test]
    public function testAReversedPaymentIsDueAgainAndOnlyActiveAccountsAreListed(): void
    {
        $year = $this->openYear();
        $entry = $this->pay($year, $this->musician->id(), '2025-11');
        $this->entries->reverse($this->admin, $entry->id, ['reason' => 'Erreur', 'occurredOn' => '2025-11-06'], $this->clock);

        self::assertSame(CotisationCell::APayer, $this->cellsOf($year, $this->musician->id())['2025-11']);
        $table = $this->dues->table($this->admin, $this->yearRepository->find($year) ?? throw new \LogicException(), $this->today);
        self::assertNotContains($this->gone->id(), array_map(static fn (array $r): int => $r['member']->id, $table->rows));
    }

    #[Test]
    public function testAnExemptionCanBeSetAndRemovedButNotOnAPaidMonth(): void
    {
        $year = $this->openYear();
        $member = $this->musician->id();
        $this->pay($year, $member, '2025-11');

        $this->dues->setExemption($this->admin, ['memberUserId' => (string) $member, 'periodMonth' => '2025-10', 'exempt' => true], $this->clock);
        self::assertSame(CotisationCell::Dispensee, $this->cellsOf($year, $member)['2025-10']);
        $this->dues->setExemption($this->admin, ['memberUserId' => (string) $member, 'periodMonth' => '2025-10', 'exempt' => true], $this->clock);
        $this->dues->setExemption($this->admin, ['memberUserId' => (string) $member, 'periodMonth' => '2025-10', 'exempt' => false], $this->clock);
        self::assertSame(CotisationCell::APayer, $this->cellsOf($year, $member)['2025-10']);

        try {
            $this->dues->setExemption($this->admin, ['memberUserId' => (string) $member, 'periodMonth' => '2025-11', 'exempt' => true], $this->clock);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('periodMonth', $e->fields());
        }
    }

    #[Test]
    public function testInvalidExemptionsAreRefusedByField(): void
    {
        foreach ([
            ['memberUserId' => 'x', 'periodMonth' => '2025-10', 'exempt' => true, 'field' => 'memberUserId'],
            ['memberUserId' => '99999999', 'periodMonth' => '2025-10', 'exempt' => true, 'field' => 'memberUserId'],
            ['memberUserId' => (string) $this->gone->id(), 'periodMonth' => '2025-10', 'exempt' => true, 'field' => 'memberUserId'],
            ['memberUserId' => (string) $this->musician->id(), 'periodMonth' => '2025-10-01', 'exempt' => true, 'field' => 'periodMonth'],
            ['memberUserId' => (string) $this->musician->id(), 'periodMonth' => '2025-10', 'exempt' => 'oui', 'field' => 'exempt'],
        ] as $case) {
            $field = $case['field'];
            unset($case['field']);
            try {
                $this->dues->setExemption($this->admin, $case, $this->clock);
                self::fail("refus attendu pour {$field}");
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey($field, $e->fields());
            }
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_member_exemptions')->fetchColumn());
    }

    #[Test]
    public function testOnlyAdministratorsSeeOrChangeTheTable(): void
    {
        $year = $this->yearRepository->find($this->openYear()) ?? throw new \LogicException();
        $denied = 0;
        foreach ([$this->musician, $this->gone] as $actor) {
            foreach ([
                fn () => $this->dues->table($actor, $year, $this->today),
                fn () => $this->dues->setExemption($actor, ['memberUserId' => '1', 'periodMonth' => '2025-10', 'exempt' => true]),
            ] as $call) {
                try {
                    $call();
                } catch (AccessDeniedException) {
                    ++$denied;
                }
            }
        }
        self::assertSame(4, $denied);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_member_exemptions')->fetchColumn());
    }
}
