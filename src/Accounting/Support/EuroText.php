<?php

declare(strict_types=1);

namespace App\Accounting\Support;

/** Montant en centimes entiers écrit en euros « 1234,50 » (virgule, deux décimales, sans espace ni symbole) : pour les champs de saisie et les exports. */
final class EuroText
{
    public static function plain(int $cents): string
    {
        $absolute = abs($cents);

        return ($cents < 0 ? '-' : '') . intdiv($absolute, 100) . ',' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
