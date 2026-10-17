<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\Statement;
use App\Accounting\Repository\EntryRepositoryInterface;

/**
 * Écart d'un relevé avec le livre (#362) : solde de la BANQUE selon le livre à la fin de la période (solde d'ouverture de l'exercice + écritures
 * hors espèces jusqu'à la date de fin) comparé au solde saisi du relevé. Sans solde saisi, pas d'écart. Aucun droit ici : l'appelant (service
 * d'un administrateur, ou tâche planifiée de rappel) a déjà jugé qui demande.
 */
final class StatementGapCalculator
{
    public function __construct(private readonly EntryRepositoryInterface $entries)
    {
    }

    public function review(FiscalYear $year, Statement $statement): StatementReview
    {
        $ledger = $year->openingBalanceCents + $this->entries->bankNetUntil($year->id, $statement->periodEnd);

        return new StatementReview($statement, $ledger, $statement->closingBalanceCents === null ? null : $statement->closingBalanceCents - $ledger);
    }
}
