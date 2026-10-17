<?php

declare(strict_types=1);

namespace App\Accounting\Support;

/** Un mois saisi au format AAAA-MM, lu strictement : un texte de cette forme exacte, mois de 01 à 12, années 1900 à 2999 ; tout le reste est refusé. */
final class MonthInput
{
    /** @return \DateTimeImmutable|null le premier jour du mois, ou null si la valeur n'est pas un mois valide */
    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (is_string($value) && preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $value) === 1 && $value >= '1900-01' && $value <= '2999-12') {
            return new \DateTimeImmutable($value . '-01');
        }

        return null;
    }
}
