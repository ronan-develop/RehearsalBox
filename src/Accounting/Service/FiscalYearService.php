<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Account\Entity\User;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearChangedException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Cycle de vie des exercices (#362), réservé aux administrateurs actifs : ouvert, clôturé, approuvé en assemblée générale, jamais de retour
 * en arrière. Chaque opération vérifie le droit, puis écrit dans UNE transaction sous le verrou de la ligne d'exercice (même verrou que la saisie
 * d'une écriture) : une clôture ne peut pas s'intercaler dans une saisie, ni l'inverse. Le journal ne reçoit que des identifiants.
 */
final class FiscalYearService
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly FiscalYearInputPolicy $policy,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryRepositoryInterface $entries,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input `label`, `startsOn`, `endsOn`, `openingBalance` (le tout premier exercice seulement)
     * @throws AccountingValidationException nom pris (clé unique), exercice précédent non clos, début qui ne suit pas la fin du précédent
     */
    public function create(User $actor, array $input, ?\DateTimeImmutable $now = null): int
    {
        $this->access->assertAdmin($actor);
        [$label, $startsOn, $endsOn, $typedOpening] = $this->policy->parse($input);
        $now ??= new \DateTimeImmutable();

        $id = $this->transactions->run(function () use ($label, $startsOn, $endsOn, $typedOpening, $actor, $now): int {
            $this->years->lockAll();
            $errors = [];
            $previous = $this->years->latest();
            $opening = $typedOpening ?? 0;
            if ($previous !== null) {
                if ($previous->closingBalanceCents === null) {
                    $errors['startsOn'] = 'L\'exercice précédent doit être clôturé avant d\'en ouvrir un nouveau.';
                } elseif ($startsOn->format('Y-m-d') !== $previous->endsOn->modify('+1 day')->format('Y-m-d')) {
                    $errors['startsOn'] = 'Un exercice commence le lendemain de la fin du précédent (' . $previous->endsOn->modify('+1 day')->format('d/m/Y') . ').';
                } else {
                    $opening = $previous->closingBalanceCents;
                }
            }
            if ($errors !== []) {
                throw new AccountingValidationException($errors);
            }

            return $this->years->insert($label, $startsOn, $endsOn, $opening, $actor->id(), $now);
        });
        $this->logger->info('Comptabilité : exercice créé', ['actor' => $actor->id(), 'year' => $id]);

        return $id;
    }

    /** @return list<FiscalYear> */
    public function list(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->years->listAll();
    }

    /** @throws FiscalYearNotFoundException */
    public function get(User $actor, int $id): FiscalYear
    {
        $this->access->assertAdmin($actor);

        return $this->years->find($id) ?? throw new FiscalYearNotFoundException();
    }

    /**
     * Soldes et totaux, calculés à la lecture (jamais stockés).
     *
     * @return array{year: FiscalYear, totals: LedgerTotals, balanceCents: int}
     * @throws FiscalYearNotFoundException
     */
    public function summary(User $actor, int $id): array
    {
        $year = $this->get($actor, $id);
        $totals = $this->entries->totals($id);

        return ['year' => $year, 'totals' => $totals, 'balanceCents' => $year->openingBalanceCents + $totals->netCents()];
    }

    /**
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException exercice déjà clos
     * @throws FiscalYearChangedException révision périmée
     */
    public function close(User $actor, int $id, int $expectedRevision, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();

        $this->transactions->run(function () use ($id, $expectedRevision, $actor, $now): void {
            $year = $this->years->lock($id) ?? throw new FiscalYearNotFoundException();
            if (!$year->isOpen()) {
                throw new FiscalYearLockedException();
            }
            if ($year->revision !== $expectedRevision) {
                throw new FiscalYearChangedException();
            }
            $closing = $year->openingBalanceCents + $this->entries->totals($id)->netCents();
            if (!$this->years->close($id, $expectedRevision, $closing, $actor->id(), $now)) {
                throw new FiscalYearChangedException();
            }
        });
        $this->logger->info('Comptabilité : exercice clôturé', ['actor' => $actor->id(), 'year' => $id]);
    }

    /**
     * @param array<string, mixed> $input `approvedOn` : date de l'assemblée générale
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException exercice non clôturé, ou déjà approuvé
     * @throws AccountingValidationException date invalide, avant la fin de l'exercice ou dans le futur
     */
    public function approve(User $actor, int $id, array $input, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();

        $this->transactions->run(function () use ($id, $input, $actor, $now): void {
            $year = $this->years->lock($id) ?? throw new FiscalYearNotFoundException();
            if ($year->status !== FiscalYearStatus::Cloture) {
                throw new FiscalYearLockedException('Seul un exercice clôturé, et pas encore approuvé, peut être approuvé.');
            }
            $date = $this->policy->parseApproval($input);
            if ($date < $year->endsOn || $date->format('Y-m-d') > $now->format('Y-m-d')) {
                throw new AccountingValidationException(['approvedOn' => 'La date d\'approbation ne peut pas précéder la fin de l\'exercice ni être dans le futur.']);
            }
            if (!$this->years->approve($id, $date, $actor->id(), $now)) {
                throw new FiscalYearLockedException();
            }
        });
        $this->logger->info('Comptabilité : exercice approuvé', ['actor' => $actor->id(), 'year' => $id]);
    }
}
