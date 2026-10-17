<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Account\Entity\User;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\EntryNotFoundException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Accounting\Repository\MemberDuesRepositoryInterface;
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
        private readonly MemberDuesRepositoryInterface $members,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input voir EntryInputPolicy::parse
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException exercice clos ou approuvé
     * @param (callable(AccountingEntry): void)|null $inTransaction appelé APRÈS l'écriture et DANS la même transaction : s'il lève, rien n'est écrit
     * @throws AccountingValidationException saisie refusée, ou date hors de l'exercice
     */
    public function record(User $actor, int $fiscalYearId, array $input, ?\DateTimeImmutable $now = null, ?callable $inTransaction = null): AccountingEntry
    {
        $this->access->assertAdmin($actor);
        $draft = $this->policy->parse($input);
        $now ??= new \DateTimeImmutable();

        $entry = $this->transactions->run(function () use ($fiscalYearId, $draft, $actor, $now, $inTransaction): AccountingEntry {
            $year = $this->years->lock($fiscalYearId) ?? throw new FiscalYearNotFoundException();
            $this->assertAccepts($year, $draft);

            $this->assertDuesAreFree($draft);
            $entry = $this->write($year->id, $year->nextEntryNumber, $draft, null, $actor, $now);
            if ($inTransaction !== null) {
                $inTransaction($entry);
            }

            return $entry;
        });
        $this->logger->info('Comptabilité : écriture saisie', ['actor' => $actor->id(), 'year' => $fiscalYearId, 'entry' => $entry->id]);

        return $entry;
    }

    /**
     * Plusieurs écritures en UNE transaction : tout ou rien (validation en lot de l'import CSV). Chaque élément donne l'exercice (par son
     * identifiant), la saisie (mêmes règles que `record`) et, facultativement, un rappel exécuté dans la transaction après son écriture. Les
     * exercices sont verrouillés dans l'ordre de leur identifiant (jamais d'interblocage entre deux lots) ; les numéros se suivent sans trou,
     * dans l'ordre des éléments. Toutes les saisies refusées sont rapportées ensemble, clés `<clé de l'élément>.<champ>`.
     *
     * @param array<string|int, array{year: int, input: array<string, mixed>, after?: callable(AccountingEntry): void}> $items
     * @return array<string|int, AccountingEntry> les écritures, avec les clés des éléments
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException
     * @throws AccountingValidationException
     */
    public function recordBatch(User $actor, array $items, ?\DateTimeImmutable $now = null): array
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();
        $drafts = [];
        $errors = [];
        foreach ($items as $key => $item) {
            try {
                $drafts[$key] = $this->policy->parse($item['input']);
            } catch (AccountingValidationException $e) {
                foreach ($e->fields() as $field => $message) {
                    $errors["{$key}.{$field}"] = $message;
                }
            }
        }
        if ($errors !== []) {
            throw new AccountingValidationException($errors);
        }

        $entries = $this->transactions->run(function () use ($items, $drafts, $actor, $now): array {
            $yearIds = array_unique(array_map(static fn (array $item): int => $item['year'], $items));
            sort($yearIds);
            $locked = [];
            foreach ($yearIds as $yearId) {
                $locked[$yearId] = $this->years->lock($yearId) ?? throw new FiscalYearNotFoundException();
            }
            $next = array_map(static fn (FiscalYear $y): int => $y->nextEntryNumber, $locked);
            $written = [];
            foreach ($items as $key => $item) {
                $yearId = $item['year'];
                try {
                    $this->assertAccepts($locked[$yearId], $drafts[$key]);
                    $this->assertDuesAreFree($drafts[$key]);
                } catch (AccountingValidationException $e) {
                    throw new AccountingValidationException(array_combine(array_map(static fn (string $f): string => "{$key}.{$f}", array_keys($e->fields())), $e->fields()));
                }
                $written[$key] = $this->write($yearId, $next[$yearId]++, $drafts[$key], null, $actor, $now);
                if (isset($item['after'])) {
                    $item['after']($written[$key]);
                }
            }

            return $written;
        });
        $this->logger->info('Comptabilité : écritures saisies en lot', ['actor' => $actor->id(), 'count' => count($entries)]);

        return $entries;
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

    /**
     * L'exercice verrouillé accepte-t-il cette écriture : ouvert, et la date y tient.
     *
     * @throws FiscalYearLockedException
     * @throws AccountingValidationException
     */
    private function assertAccepts(FiscalYear $year, EntryDraft $draft): void
    {
        if (!$year->isOpen()) {
            throw new FiscalYearLockedException();
        }
        if (!$year->contains($draft->occurredOn)) {
            throw new AccountingValidationException(['occurredOn' => 'La date doit être comprise dans l\'exercice (du ' . $year->startsOn->format('d/m/Y') . ' au ' . $year->endsOn->format('d/m/Y') . ').']);
        }
    }

    /**
     * Cotisation d'un adhérent (#362) : le compte doit être actif et le mois ne doit pas être déjà payé (hors écriture annulée). Jugé sous le
     * verrou de l'exercice, dans la transaction de l'écriture.
     *
     * @throws AccountingValidationException
     */
    private function assertDuesAreFree(EntryDraft $draft): void
    {
        if ($draft->memberUserId === null || $draft->periodMonth === null) {
            return;
        }
        if (!$this->members->isActiveMember($draft->memberUserId)) {
            throw new AccountingValidationException(['memberUserId' => 'Ce compte est introuvable ou désactivé.']);
        }
        if ($this->entries->memberMonthPaid($draft->memberUserId, $draft->periodMonth)) {
            throw new AccountingValidationException(['periodMonth' => 'La cotisation de cet adhérent est déjà enregistrée pour ce mois.']);
        }
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
