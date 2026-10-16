<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\Statement;

/**
 * Accès à `accounting_statements`. Un relevé est IMMUABLE : pas de modification ni de suppression ; la seule évolution est d'être marqué
 * « remplacé » par un nouveau relevé.
 */
interface StatementRepositoryInterface
{
    public function insert(int $fiscalYearId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, ?int $closingBalanceCents, string $originalName, string $storedName, string $mime, int $sizeBytes, string $sha256, int $actorId, \DateTimeImmutable $now): int;

    public function find(int $id): ?Statement;

    /** @return list<Statement> par période puis identifiant, au plus `$limit` */
    public function listByYear(int $fiscalYearId, int $limit): array;

    public function countByYear(int $fiscalYearId): int;

    public function totalBytesByYear(int $fiscalYearId): int;

    public function hashExists(int $fiscalYearId, string $sha256): bool;

    /** Marque `$id` remplacé par `$newId` ; faux s'il l'était déjà. */
    public function markReplaced(int $id, int $newId): bool;
}
