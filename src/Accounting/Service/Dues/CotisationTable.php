<?php

declare(strict_types=1);

namespace App\Accounting\Service\Dues;

use App\Accounting\Entity\Member;

/** Tableau adhérents × mois d'un exercice, calculé à partir du livre : rien n'est stocké en double. */
final class CotisationTable
{
    /**
     * @param list<\DateTimeImmutable> $months premiers jours des mois de l'exercice
     * @param list<array{member: Member, cells: array<string, CotisationCell>}> $rows cellules indexées par `AAAA-MM`
     */
    public function __construct(
        public readonly array $months,
        public readonly array $rows,
    ) {
    }

    public function dueCount(): int
    {
        $count = 0;
        foreach ($this->rows as $row) {
            foreach ($row['cells'] as $cell) {
                $count += $cell === CotisationCell::APayer ? 1 : 0;
            }
        }

        return $count;
    }
}
