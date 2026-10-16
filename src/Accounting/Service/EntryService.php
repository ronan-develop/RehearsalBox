<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Account\Entity\User;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\EntryNotFoundException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Écriture du journal (#362), réservée aux administrateurs actifs. Toute écriture se fait dans UNE transaction, sous le verrou de la ligne
 * d'exercice : on y lit l'état (ouvert) et le numéro suivant, on insère, on incrémente : jamais de trou ni de doublon, même à deux, et un
 * exercice clos n'accepte plus rien. Une écriture ne se modifie ni ne se supprime : une erreur se corrige par une contre-passation,
 * datée dans l'exercice OUVERT (un exercice clos ne bouge jamais), liée à l'originale, une seule fois, jamais sur une contre-passation.
 * Le journal ne reçoit que des identifiants, jamais un libellé ni un montant.
 */
final class EntryService
{
    private const LIST_LIMIT = 1000;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly EntryInputPolicy $policy,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly EntryRepositoryInterface $entries,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input voir EntryInputPolicy::parse
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException exercice clos ou approuvé
     * @throws AccountingValidationException saisie refusée, ou date hors de l'exercice
     */
    public function record(User $actor, int $fiscalYearId, array $input, ?\DateTimeImmutable $now = null): AccountingEntry
    {
        $this->access->assertAdmin($actor);
        $draft = $this->policy->parse($input);
        $now ??= new \DateTimeImmutable();

        $entry = $this->transactions->run(function () use ($fiscalYearId, $draft, $actor, $now): AccountingEntry {
            $year = $this->years->lock($fiscalYearId) ?? throw new FiscalYearNotFoundException();
            if (!$year->isOpen()) {
                throw new FiscalYearLockedException();
            }
            if (!$year->contains($draft->occurredOn)) {
                throw new AccountingValidationException(['occurredOn' => 'La date doit être comprise dans l\'exercice (du ' . $year->startsOn->format('d/m/Y') . ' au ' . $year->endsOn->format('d/m/Y') . ').']);
            }

            return $this->write($year->id, $year->nextEntryNumber, $draft, null, $actor, $now);
        });
        $this->logger->info('Comptabilité : écriture saisie', ['actor' => $actor->id(), 'year' => $fiscalYearId, 'entry' => $entry->id]);

        return $entry;
    }

    /**
     * @param array<string, mixed> $input `reason` et `occurredOn` (date de la contre-passation)
     * @throws EntryNotFoundException
     * @throws AccountingValidationException motif ou date invalide, aucun exercice ouvert à cette date, écriture déjà annulée ou déjà une annulation
     */
    public function reverse(User $actor, int $entryId, array $input, ?\DateTimeImmutable $now = null): AccountingEntry
    {
        $this->access->assertAdmin($actor);
        [$reason, $date] = $this->policy->parseReversal($input);
        $now ??= new \DateTimeImmutable();

        $reversal = $this->transactions->run(function () use ($entryId, $reason, $date, $actor, $now): AccountingEntry {
            $original = $this->entries->find($entryId) ?? throw new EntryNotFoundException();
            if ($original->reversesEntryId !== null) {
                throw new AccountingValidationException(['entry' => 'Une annulation ne s\'annule pas : saisissez une nouvelle écriture.']);
            }
            if ($this->entries->reversalOf($entryId) !== null) {
                throw new AccountingValidationException(['entry' => 'Cette écriture est déjà annulée.']);
            }
            $candidate = $this->years->findOpenContaining($date) ?? throw new AccountingValidationException(['occurredOn' => 'Aucun exercice ouvert à cette date.']);
            $year = $this->years->lock($candidate->id);
            if ($year === null || !$year->isOpen()) {
                throw new AccountingValidationException(['occurredOn' => 'Aucun exercice ouvert à cette date.']);
            }
            $source = $this->years->find($original->fiscalYearId);
            $draft = new EntryDraft(
                $date,
                'Annulation de l\'écriture n° ' . $original->number . ($source !== null ? ' (exercice ' . $source->label . ')' : ''),
                $original->amountCents,
                $original->direction->opposite(),
                $original->category,
                $original->paymentMethod,
                null,
                $reason,
            );

            return $this->write($year->id, $year->nextEntryNumber, $draft, $original->id, $actor, $now);
        });
        $this->logger->info('Comptabilité : écriture annulée', ['actor' => $actor->id(), 'entry' => $entryId, 'reversal' => $reversal->id]);

        return $reversal;
    }

    /**
     * @return list<AccountingEntry>
     * @throws FiscalYearNotFoundException
     */
    public function list(User $actor, int $fiscalYearId): array
    {
        $this->access->assertAdmin($actor);
        $this->years->find($fiscalYearId) ?? throw new FiscalYearNotFoundException();

        return $this->entries->listByYear($fiscalYearId, self::LIST_LIMIT);
    }

    /** À appeler sous le verrou de l'exercice : insère, prend le numéro suivant, relit l'écriture. */
    private function write(int $yearId, int $number, EntryDraft $draft, ?int $reverses, User $actor, \DateTimeImmutable $now): AccountingEntry
    {
        $id = $this->entries->insert($yearId, $number, $draft, $reverses, $actor->id(), $now);
        if (!$this->years->incrementNextNumber($yearId)) {
            throw new FiscalYearLockedException();
        }

        return $this->entries->find($id) ?? throw new EntryNotFoundException();
    }
}
