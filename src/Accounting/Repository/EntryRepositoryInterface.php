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

    public function totals(int $fiscalYearId): LedgerTotals;

    /** Mouvement net de la BANQUE (tout sauf les espèces) de l'exercice jusqu'à `$date` incluse : recettes moins dépenses. */
    public function bankNetUntil(int $fiscalYearId, \DateTimeImmutable $date): int;
}
