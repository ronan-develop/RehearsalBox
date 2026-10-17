<?php

declare(strict_types=1);

namespace App\Accounting\Import\Parse;

use App\Accounting\Exception\AccountingValidationException;

/** Profil de colonnes saisi à l'import (#362) : chaque valeur vient d'une liste fermée ou d'un entier borné, toutes les erreurs remontées ensemble par champ. */
final class ImportProfilePolicy
{
    private const MAX_COLUMN = 30;
    private const MAX_SKIP = 10;
    private const DELIMITERS = ['semicolon' => ';', 'comma' => ',', 'tab' => "\t"];

    /**
     * @param array<string, mixed> $input `delimiter` (semicolon, comma, tab), `encoding`, `skipRows`, `dateColumn`, `labelColumn`, `amountMode`
     *                                    (signed, split), `amountColumn` OU `debitColumn` et `creditColumn`, `decimalSeparator`, `dateFormat`
     * @throws AccountingValidationException
     */
    public function parse(array $input): CsvProfile
    {
        $errors = [];
        $delimiter = self::DELIMITERS[self::text($input['delimiter'] ?? null)] ?? null;
        $encoding = self::choice($input['encoding'] ?? null, CsvProfile::ENCODINGS);
        $mode = self::choice($input['amountMode'] ?? null, [CsvProfile::SIGNED, CsvProfile::SPLIT]);
        $decimal = self::choice($input['decimalSeparator'] ?? null, [',', '.']);
        $dateFormat = self::choice($input['dateFormat'] ?? null, CsvProfile::DATE_FORMATS);
        $skip = self::number($input['skipRows'] ?? 0, 0, self::MAX_SKIP);
        $date = self::number($input['dateColumn'] ?? null, 1, self::MAX_COLUMN);
        $label = self::number($input['labelColumn'] ?? null, 1, self::MAX_COLUMN);
        $amount = $mode === CsvProfile::SIGNED ? self::number($input['amountColumn'] ?? null, 1, self::MAX_COLUMN) : 0;
        $debit = $mode === CsvProfile::SPLIT ? self::number($input['debitColumn'] ?? null, 1, self::MAX_COLUMN) : 0;
        $credit = $mode === CsvProfile::SPLIT ? self::number($input['creditColumn'] ?? null, 1, self::MAX_COLUMN) : 0;

        $errors += $delimiter === null ? ['delimiter' => 'Choisissez le séparateur : point-virgule, virgule ou tabulation.'] : [];
        $errors += $encoding === null ? ['encoding' => 'Choisissez l\'encodage : UTF-8 ou ISO-8859-1.'] : [];
        $errors += $mode === null ? ['amountMode' => 'Choisissez : une colonne de montant, ou deux colonnes débit et crédit.'] : [];
        $errors += $decimal === null ? ['decimalSeparator' => 'Choisissez la virgule ou le point comme séparateur décimal.'] : [];
        $errors += $dateFormat === null ? ['dateFormat' => 'Choisissez le format de date.'] : [];
        $errors += $skip === null ? ['skipRows' => 'Lignes d\'en-tête à ignorer : de 0 à ' . self::MAX_SKIP . '.'] : [];
        $errors += $date === null ? ['dateColumn' => 'Numéro de la colonne de date (à partir de 1).'] : [];
        $errors += $label === null ? ['labelColumn' => 'Numéro de la colonne du libellé (à partir de 1).'] : [];
        if ($mode === CsvProfile::SIGNED && $amount === null) {
            $errors['amountColumn'] = 'Numéro de la colonne du montant (à partir de 1).';
        }
        if ($mode === CsvProfile::SPLIT) {
            $errors += $debit === null ? ['debitColumn' => 'Numéro de la colonne des débits (à partir de 1).'] : [];
            $errors += $credit === null ? ['creditColumn' => 'Numéro de la colonne des crédits (à partir de 1).'] : [];
        }
        $used = array_filter([$date, $label, $mode === CsvProfile::SIGNED ? $amount : null, $mode === CsvProfile::SPLIT ? $debit : null, $mode === CsvProfile::SPLIT ? $credit : null], static fn ($c): bool => $c !== null && $c > 0);
        if ($errors === [] && count($used) !== count(array_unique($used))) {
            $errors['dateColumn'] = 'Chaque information doit avoir sa propre colonne.';
        }
        if ($errors !== [] || $delimiter === null || $encoding === null || $mode === null || $decimal === null || $dateFormat === null || $skip === null || $date === null || $label === null) {
            throw new AccountingValidationException($errors);
        }

        return new CsvProfile($delimiter, $encoding, $skip, $date, $label, $mode, $amount ?? 0, $debit ?? 0, $credit ?? 0, $decimal, $dateFormat);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @param list<string> $allowed */
    private static function choice(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private static function number(mixed $value, int $min, int $max): ?int
    {
        $text = is_int($value) ? (string) $value : $value;
        if (!is_string($text) || preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $text) !== 1) {
            return null;
        }
        $number = (int) $text;

        return $number >= $min && $number <= $max ? $number : null;
    }
}
