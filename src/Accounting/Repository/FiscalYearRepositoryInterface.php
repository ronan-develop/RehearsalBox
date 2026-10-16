<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\FiscalYear;
use App\Accounting\Exception\AccountingValidationException;

/**
 * Accès à `accounting_fiscal_years`. Le gel d'un exercice clos ne repose sur aucun trigger : `lock` pose le verrou de la ligne
 * (SELECT … FOR UPDATE) que prend toute écriture, et chaque mise à jour porte l'état attendu dans son WHERE.
 */
interface FiscalYearRepositoryInterface
{
    /** @throws AccountingValidationException nom déjà pris (la clé unique de la base fait foi) */
    public function insert(string $label, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, int $openingBalanceCents, int $actorId, \DateTimeImmutable $now): int;

    public function find(int $id): ?FiscalYear;

    /** À appeler dans une transaction : la ligne reste verrouillée jusqu'à son terme. */
    public function lock(int $id): ?FiscalYear;

    /** Verrouille tous les exercices (création : contrôle de chevauchement et de suite sans concurrence). */
    public function lockAll(): void;

    /** @return list<FiscalYear> du plus récent au plus ancien */
    public function listAll(): array;

    /** L'exercice qui finit le plus tard, s'il y en a un. */
    public function latest(): ?FiscalYear;

    public function findOpenContaining(\DateTimeImmutable $date): ?FiscalYear;

    /** Prend le numéro suivant ; faux si l'exercice n'est plus ouvert. */
    public function incrementNextNumber(int $id): bool;

    /** Clôture : faux si l'exercice n'est plus ouvert ou si sa révision a changé. */
    public function close(int $id, int $expectedRevision, int $closingBalanceCents, int $actorId, \DateTimeImmutable $now): bool;

    /** Approbation : faux si l'exercice n'est pas clôturé. */
    public function approve(int $id, \DateTimeImmutable $approvedOn, int $actorId, \DateTimeImmutable $now): bool;
}
