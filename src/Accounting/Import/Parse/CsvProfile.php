<?php

declare(strict_types=1);

namespace App\Accounting\Import\Parse;

/**
 * Profil de colonnes d'un fichier CSV de banque (#362) : choisi par le trésorier à l'import (aucune banque codée en dur), contrôlé par
 * ImportProfilePolicy. Les colonnes se comptent à partir de 1, comme dans un tableur. Montant : une colonne SIGNÉE (négatif = dépense) ou deux
 * colonnes DÉBIT et CRÉDIT.
 */
final class CsvProfile
{
    public const SIGNED = 'signed';
    public const SPLIT = 'split';
    public const DATE_FORMATS = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'd.m.Y'];
    public const DELIMITERS = [';', ',', "\t"];
    public const ENCODINGS = ['UTF-8', 'ISO-8859-1'];

    public function __construct(
        public readonly string $delimiter,
        public readonly string $encoding,
        public readonly int $skipRows,
        public readonly int $dateColumn,
        public readonly int $labelColumn,
        public readonly string $amountMode,
        public readonly int $amountColumn,
        public readonly int $debitColumn,
        public readonly int $creditColumn,
        public readonly string $decimalSeparator,
        public readonly string $dateFormat,
    ) {
    }
}
