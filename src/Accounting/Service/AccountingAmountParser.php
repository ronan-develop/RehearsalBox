<?php

declare(strict_types=1);

namespace App\Accounting\Service;

/**
 * Montant saisi en euros → centimes ENTIERS (#362). Aucun flottant n'intervient jamais : on lit les chiffres, on les cale en centimes
 * à la main. Seul un texte de chiffres ASCII, avec au plus deux décimales après une virgule ou un point, est accepté ; tout le reste
 * (signe, exposant, séparateur de milliers, chiffres d'une autre écriture, nombre PHP) est refusé. Plafond : 999 999 999,99 €.
 */
final class AccountingAmountParser
{
    public const MAX_CENTS = 99999999999;

    /** @return int|null centimes (strictement positifs, ou nuls si `$allowZero`), null si ce n'est pas un montant */
    public function parse(mixed $value, bool $allowZero = false): ?int
    {
        if (!is_string($value)) {
            return null;
        }
        if (preg_match('/\A[0-9]{1,9}(?:[.,]([0-9]{1,2}))?\z/D', trim($value, ' '), $match) !== 1) {
            return null;
        }
        $parts = preg_split('/[.,]/', trim($value, ' '));
        $euros = (int) $parts[0];
        $decimals = isset($match[1]) ? (int) str_pad($match[1], 2, '0') : 0;
        $cents = $euros * 100 + $decimals;

        return $cents > self::MAX_CENTS || ($cents === 0 && !$allowZero) ? null : $cents;
    }
}
