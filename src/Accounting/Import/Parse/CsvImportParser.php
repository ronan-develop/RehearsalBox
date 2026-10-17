<?php

declare(strict_types=1);

namespace App\Accounting\Import\Parse;

use App\Accounting\Entity\Direction;

/**
 * Lecture d'un CSV de banque d'après un profil de colonnes (#362), pure : du texte entre, des lignes et des refus sortent, rien n'est écrit.
 * Le fichier n'est JAMAIS exécuté ni interprété : un libellé qui commence par `=` reste du texte. Borné (1 Mo, 2000 lignes) ; binaire ou
 * encodage invalide : refus global (clé 0). Chaque ligne mal formée est signalée avec son numéro d'enregistrement et une raison qui ne répète
 * jamais le contenu. Les montants sont lus en CENTIMES ENTIERS par chiffres (jamais de flottant), avec séparateur de milliers toléré.
 */
final class CsvImportParser
{
    public function parse(string $content, CsvProfile $profile): ParseResult
    {
        $text = $this->readable($content, $profile);
        if (is_array($text)) {
            return new ParseResult([], [0 => $text[0]]);
        }

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            return new ParseResult([], [0 => 'Le fichier ne peut pas être lu.']);
        }
        fwrite($stream, $text);
        rewind($stream);

        $lines = [];
        $errors = [];
        $number = 0;
        $needed = max($profile->dateColumn, $profile->labelColumn, $profile->amountMode === CsvProfile::SIGNED ? $profile->amountColumn : max($profile->debitColumn, $profile->creditColumn));
        while (($record = fgetcsv($stream, null, $profile->delimiter, '"', '')) !== false) {
            ++$number;
            if ($number <= $profile->skipRows || $record === [null]) {
                continue;
            }
            if (count($lines) + count($errors) >= ImportLimits::MAX_RECORDS) {
                $errors[0] = 'Le fichier est coupé après ' . ImportLimits::MAX_RECORDS . ' lignes : importez-le en plusieurs fichiers.';
                break;
            }
            if (count($record) < $needed) {
                $errors[$number] = 'Colonnes manquantes : vérifiez le séparateur et les numéros de colonnes.';
                continue;
            }
            $line = $this->line($number, $record, $profile, $reason);
            if ($line === null) {
                $errors[$number] = $reason;
                continue;
            }
            $lines[] = $line;
        }
        fclose($stream);

        return new ParseResult($lines, $errors);
    }

    /** @return string|array{0: string} le texte UTF-8 prêt à lire, ou [raison du refus] */
    private function readable(string $content, CsvProfile $profile): string|array
    {
        if ($content === '') {
            return ['Le fichier est vide.'];
        }
        if (strlen($content) > ImportLimits::MAX_BYTES) {
            return ['Le fichier dépasse 1 Mo.'];
        }
        if (str_contains($content, "\0")) {
            return ['Ce fichier n\'est pas un fichier texte (CSV attendu).'];
        }
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
        if ($profile->encoding === 'ISO-8859-1') {
            return mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            return ['Le texte n\'est pas en UTF-8 : choisissez l\'encodage ISO-8859-1.'];
        }

        return $content;
    }

    /** @param list<string|null> $record */
    private function line(int $number, array $record, CsvProfile $profile, ?string &$reason): ?ParsedLine
    {
        $date = $this->date((string) ($record[$profile->dateColumn - 1] ?? ''), $profile->dateFormat);
        if ($date === null) {
            $reason = 'Date illisible : vérifiez le format de date.';

            return null;
        }
        $label = $this->label((string) ($record[$profile->labelColumn - 1] ?? ''));
        if ($label === '') {
            $reason = 'Libellé vide.';

            return null;
        }
        $amount = $this->amount($record, $profile, $reason);
        if ($amount === null) {
            return null;
        }

        return new ParsedLine($number, $date, $label, $amount[0], $amount[1]);
    }

    private function date(string $value, string $format): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, trim($value));
        $problems = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($problems !== false && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))) {
            return null;
        }
        $year = (int) $date->format('Y');

        return $year >= 1990 && $year <= 2100 ? $date : null;
    }

    private function label(string $raw): string
    {
        $text = (string) preg_replace('/[\r\n\t]+/u', ' ', $raw);
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $text);
        $text = trim((string) preg_replace('/ {2,}/u', ' ', $text));

        return mb_substr($text, 0, ImportLimits::LABEL_MAX);
    }

    /**
     * @param list<string|null> $record
     * @return array{0: int, 1: Direction}|null
     */
    private function amount(array $record, CsvProfile $profile, ?string &$reason): ?array
    {
        if ($profile->amountMode === CsvProfile::SIGNED) {
            $parsed = $this->cents((string) ($record[$profile->amountColumn - 1] ?? ''), $profile->decimalSeparator);
            if ($parsed === null) {
                $reason = 'Montant illisible, nul ou trop grand.';

                return null;
            }

            return [$parsed[0], $parsed[1] ? Direction::Depense : Direction::Recette];
        }
        $debit = trim((string) ($record[$profile->debitColumn - 1] ?? ''));
        $credit = trim((string) ($record[$profile->creditColumn - 1] ?? ''));
        if (($debit === '') === ($credit === '')) {
            $reason = $debit === '' ? 'Ni débit ni crédit.' : 'Débit et crédit remplis ensemble.';

            return null;
        }
        $parsed = $this->cents($debit !== '' ? $debit : $credit, $profile->decimalSeparator);
        if ($parsed === null) {
            $reason = 'Montant illisible, nul ou trop grand.';

            return null;
        }

        return [$parsed[0], $debit !== '' ? Direction::Depense : Direction::Recette];
    }

    /** @return array{0: int, 1: bool}|null centimes (toujours > 0) et vrai si le texte portait un signe moins ; null si illisible */
    private function cents(string $raw, string $decimal): ?array
    {
        $text = str_replace([' ', "\u{a0}", "\u{202f}", "\u{2009}"], '', trim($raw));
        $negative = false;
        if ($text !== '' && ($text[0] === '-' || $text[0] === '+')) {
            $negative = $text[0] === '-';
            $text = substr($text, 1);
        } elseif (str_starts_with($text, '−')) {
            $negative = true;
            $text = substr($text, strlen('−'));
        }
        $thousands = $decimal === ',' ? '.' : ',';
        $pattern = '/\A(?<int>[0-9]+|[0-9]{1,3}(?:' . preg_quote($thousands, '/') . '[0-9]{3})+)(?:' . preg_quote($decimal, '/') . '(?<frac>[0-9]{1,2}))?\z/D';
        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }
        $digits = str_replace($thousands, '', $m['int']);
        if (strlen(ltrim($digits, '0')) > ImportLimits::AMOUNT_MAX_DIGITS) {
            return null;
        }
        $cents = (int) $digits * 100 + (int) str_pad($m['frac'] ?? '', 2, '0');

        return $cents > 0 ? [$cents, $negative] : null;
    }
}
