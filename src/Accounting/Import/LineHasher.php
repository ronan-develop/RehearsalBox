<?php

declare(strict_types=1);

namespace App\Accounting\Import;

use App\Accounting\Import\Parse\ParsedLine;

/**
 * Empreinte d'une ligne de banque (#362) : date, montant, sens et libellé normalisé (minuscules, espaces réduits), plus son rang parmi les lignes
 * identiques DU MÊME fichier (deux cafés identiques le même jour sont deux lignes, mais le même fichier réimporté ne donne rien de nouveau).
 * Seule l'empreinte survit à la purge : elle ne permet pas de retrouver le libellé.
 */
final class LineHasher
{
    public static function hash(ParsedLine $line, int $rankInFile): string
    {
        $label = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $line->label)));

        return hash('sha256', implode('|', [$line->date->format('Y-m-d'), $line->amountCents, $line->direction->value, $label, $rankInFile]));
    }
}
