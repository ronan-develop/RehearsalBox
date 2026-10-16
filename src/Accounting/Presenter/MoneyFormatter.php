<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

/** Montant en centimes entiers → « 1 234,56 € » à la française (espace fine insécable entre les milliers) : le calcul des centimes reste en entiers. */
final class MoneyFormatter
{
    public function format(int $cents, bool $withSign = false): string
    {
        $absolute = abs($cents);
        $euros = intdiv($absolute, 100);
        $text = number_format($euros, 0, '', "\u{202f}") . ',' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT) . "\u{a0}€";

        if ($cents < 0) {
            return '−' . $text;
        }

        return $withSign && $cents > 0 ? '+' . $text : $text;
    }
}
