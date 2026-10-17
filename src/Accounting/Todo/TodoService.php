<?php

declare(strict_types=1);

namespace App\Accounting\Todo;

use App\Account\Entity\User;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Accounting\Repository\Month\MonthMarkRepositoryInterface;
use App\Accounting\Service\Recurring\RecurringOccurrenceService;
use App\Accounting\Service\StatementService;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * « À faire » du trésorier (#362), réservé aux administrateurs actifs : rassemble l'état du livre (relevés et écarts, mois saisis, échéances
 * récurrentes en attente) pour le TodoPlanner, et enregistre « Rien à saisir ce mois-ci » (un mois écoulé de l'exercice ouvert, une fois ;
 * ce n'est pas une écriture). Le journal ne reçoit que des identifiants.
 */
final class TodoService
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly TodoPlanner $planner,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryRepositoryInterface $entries,
        private readonly MonthMarkRepositoryInterface $marks,
        private readonly StatementService $statements,
        private readonly RecurringOccurrenceService $recurring,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<TodoItem> */
    public function forYear(User $actor, FiscalYear $year, \DateTimeImmutable $today): array
    {
        $this->access->assertAdmin($actor);
        if (!$year->isOpen()) {
            return $this->planner->plan($year, $today, [], [], [], 0);
        }

        return $this->planner->plan(
            $year,
            $today,
            $this->statements->review($actor, $year->id),
            $this->entries->monthsWithEntries($year->id),
            $this->marks->marked($year->id),
            count($this->recurring->pending($actor, $year, $today)),
        );
    }

    /**
     * @param array<string, mixed> $input `month` (AAAA-MM)
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException exercice clos
     * @throws AccountingValidationException mois invalide, hors exercice, pas encore écoulé
     */
    public function markEmptyMonth(User $actor, int $yearId, array $input, \DateTimeImmutable $today, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        $year = $this->years->find($yearId) ?? throw new FiscalYearNotFoundException();
        if (!$year->isOpen()) {
            throw new FiscalYearLockedException();
        }
        $raw = $input['month'] ?? null;
        $month = is_string($raw) && preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $raw) === 1 && $raw >= '1900-01' && $raw <= '2999-12' ? new \DateTimeImmutable($raw . '-01') : null;
        if ($month === null || $month->modify('last day of this month') < $year->startsOn || $month > $year->endsOn) {
            throw new AccountingValidationException(['month' => 'Choisissez un mois de l\'exercice, par exemple 2025-11.']);
        }
        if ($month->modify('+1 month') > $today) {
            throw new AccountingValidationException(['month' => 'Ce mois n\'est pas encore terminé.']);
        }
        $this->marks->mark($yearId, $month, $actor->id(), $now ?? new \DateTimeImmutable());
        $this->logger->info('Comptabilité : mois sans mouvement déclaré', ['actor' => $actor->id(), 'year' => $yearId]);
    }
}
