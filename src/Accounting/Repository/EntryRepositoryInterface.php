<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Service\EntryDraft;

/**
 * Accès à `accounting_entries`. Une écriture est IMMUABLE : cette interface n'a, volontairement, ni modification ni suppression
 * (un test le vérifie) ; une erreur se corrige par une contre-passation, une nouvelle ligne liée à l'originale.
 */
interface EntryRepositoryInterface
{
    /** @throws AccountingValidationException l'écriture a déjà été contre-passée */
    public function insert(int $fiscalYearId, int $number, EntryDraft $draft, ?int $reversesEntryId, int $actorId, \DateTimeImmutable $now): int;

    public function find(int $id): ?AccountingEntry;

    /** Identifiant de l'écriture qui annule celle-ci, s'il y en a une. */
    public function reversalOf(int $id): ?int;

    /** @return list<AccountingEntry> par numéro, au plus `$limit` */
    public function listByYear(int $fiscalYearId, int $limit): array;

    /**
     * Toutes les écritures de l'exercice par numéro, lues par paquets (jamais tout en mémoire) : pour l'export complet.
     *
     * @return \Generator<int, AccountingEntry>
     */
    public function eachByYear(int $fiscalYearId): \Generator;

    public function totals(int $fiscalYearId): LedgerTotals;

    /** Mouvement net de la BANQUE (tout sauf les espèces) de l'exercice jusqu'à `$date` incluse : recettes moins dépenses. */
    /** Une cotisation non annulée existe-t-elle déjà pour cet adhérent et ce mois (premier jour) ? */
    public function memberMonthPaid(int $memberUserId, \DateTimeImmutable $periodMonth): bool;

    /**
     * Cotisations non annulées dont le mois couvert est dans l'intervalle.
     *
     * @return array<string, true> clés `idAdhérent|AAAA-MM-01`
     */
    public function paidMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function bankNetUntil(int $fiscalYearId, \DateTimeImmutable $date): int;
}
