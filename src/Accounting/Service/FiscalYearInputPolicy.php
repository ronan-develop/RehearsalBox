<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Exception\AccountingValidationException;

/** Saisie d'un exercice et de son approbation (#362) : validée AVANT la base, toutes les erreurs remontées ensemble par champ. */
final class FiscalYearInputPolicy
{
    public const LABEL_MAX = 60;

    public function __construct(private readonly AccountingAmountParser $amounts)
    {
    }

    /**
     * @param array<string, mixed> $input `label`, `startsOn`, `endsOn`, `openingBalance` (facultatif, seulement pour le tout premier exercice)
     * @return array{0: string, 1: \DateTimeImmutable, 2: \DateTimeImmutable, 3: int|null} nom, début, fin, solde d'ouverture saisi en centimes
     * @throws AccountingValidationException
     */
    public function parse(array $input): array
    {
        $errors = [];
        $label = EntryInputPolicy::line($input['label'] ?? null, 'label', self::LABEL_MAX, true, 'Le nom de l\'exercice est obligatoire.', $errors);
        $startsOn = EntryInputPolicy::date($input['startsOn'] ?? null, 'startsOn', $errors);
        $endsOn = EntryInputPolicy::date($input['endsOn'] ?? null, 'endsOn', $errors);
        if ($startsOn !== null && $endsOn !== null) {
            if ($endsOn <= $startsOn) {
                $errors['endsOn'] = 'La fin doit être après le début.';
            } elseif ($endsOn >= $startsOn->modify('+2 years')) {
                $errors['endsOn'] = 'Un exercice ne dépasse pas 24 mois.';
            }
        }
        $opening = null;
        $raw = $input['openingBalance'] ?? null;
        if ($raw !== null && $raw !== '') {
            $opening = $this->amounts->parse($raw, true);
            if ($opening === null) {
                $errors['openingBalance'] = 'Saisissez un solde en euros, par exemple 350,00 (zéro ou plus).';
            }
        }
        if ($errors !== [] || $label === null || $startsOn === null || $endsOn === null) {
            throw new AccountingValidationException($errors);
        }

        return [$label, $startsOn, $endsOn, $opening];
    }

    /**
     * @param array<string, mixed> $input `approvedOn`
     * @throws AccountingValidationException
     */
    public function parseApproval(array $input): \DateTimeImmutable
    {
        $errors = [];
        $date = EntryInputPolicy::date($input['approvedOn'] ?? null, 'approvedOn', $errors);
        if ($date === null) {
            throw new AccountingValidationException($errors);
        }

        return $date;
    }
}
