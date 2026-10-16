<?php

declare(strict_types=1);

namespace App\Accounting\Controller;

use App\Accounting\Entity\FiscalYear;

/** Exercice affiché par une page de la comptabilité : celui de l'adresse s'il existe, sinon le plus récent (un identifiant inconnu est ignoré). */
final class YearChooser
{
    /** @param list<FiscalYear> $years du plus récent au plus ancien */
    public static function choose(array $years, ?int $requested): ?FiscalYear
    {
        foreach ($years as $year) {
            if ($year->id === $requested) {
                return $year;
            }
        }

        return $years[0] ?? null;
    }
}
