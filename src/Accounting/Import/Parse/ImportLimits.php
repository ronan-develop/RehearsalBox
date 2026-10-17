<?php

declare(strict_types=1);

namespace App\Accounting\Import\Parse;

/** Plafonds de l'import CSV (#362) : un fichier de banque d'une association est petit ; au-delà, c'est autre chose qu'un relevé. */
final class ImportLimits
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_RECORDS = 2000;
    /** Même plafond que le libellé d'une écriture : ce qui est relu peut entrer au livre tel quel. */
    public const LABEL_MAX = 150;
    public const AMOUNT_MAX_DIGITS = 9;
    public const PREVIEW_LINES = 20;
}
