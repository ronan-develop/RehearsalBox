<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Month;

/** Mois déclarés « rien à saisir » d'un exercice. Jamais défaits. */
interface MonthMarkRepositoryInterface
{
    /** Sans effet si le mois est déjà marqué. */
    public function mark(int $fiscalYearId, \DateTimeImmutable $month, int $actorId, \DateTimeImmutable $now): void;

    /** @return array<string, true> mois marqués, clés `AAAA-MM` */
    public function marked(int $fiscalYearId): array;
}
