<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Exception\AccountingValidationException;
use App\Support\StrictId;

/** Saisie du dépôt d'un relevé (#362) : période, solde final facultatif, relevé remplacé facultatif ; toutes les erreurs remontées ensemble. */
final class StatementInputPolicy
{
    public function __construct(private readonly AccountingAmountParser $amounts)
    {
    }

    /**
     * @param array<string, mixed> $input `periodStart`, `periodEnd`, `closingBalance` (euros, facultatif), `replaces` (identifiant, facultatif)
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: int|null, 3: int|null} début, fin, solde en centimes, relevé remplacé
     * @throws AccountingValidationException
     */
    public function parse(array $input): array
    {
        $errors = [];
        $start = EntryInputPolicy::date($input['periodStart'] ?? null, 'periodStart', $errors);
        $end = EntryInputPolicy::date($input['periodEnd'] ?? null, 'periodEnd', $errors);
        if ($start !== null && $end !== null) {
            if ($end < $start) {
                $errors['periodEnd'] = 'La fin de la période ne peut pas précéder son début.';
            } elseif ($end >= $start->modify('+1 year')) {
                $errors['periodEnd'] = 'Un relevé couvre un an au plus.';
            }
        }
        $balance = null;
        $rawBalance = $input['closingBalance'] ?? null;
        if ($rawBalance !== null && $rawBalance !== '') {
            $balance = $this->amounts->parse($rawBalance, true);
            if ($balance === null) {
                $errors['closingBalance'] = 'Saisissez le solde final du relevé en euros, par exemple 350,00.';
            }
        }
        $replaces = null;
        $rawReplaces = $input['replaces'] ?? null;
        if ($rawReplaces !== null && $rawReplaces !== '') {
            $replaces = StrictId::from($rawReplaces);
            if ($replaces === null) {
                $errors['replaces'] = 'Relevé à remplacer invalide.';
            }
        }
        if ($errors !== [] || $start === null || $end === null) {
            throw new AccountingValidationException($errors);
        }

        return [$start, $end, $balance, $replaces];
    }
}
