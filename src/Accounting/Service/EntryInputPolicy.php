<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Exception\AccountingValidationException;

/**
 * Saisie d'une écriture et d'une contre-passation (#362) : validée AVANT la base, toutes les erreurs remontées ensemble par champ. Seuls les
 * champs attendus sont lus : numéro, exercice, auteur, état ou lien de contre-passation forgés dans le corps sont ignorés.
 */
final class EntryInputPolicy
{
    public const LABEL_MAX = 150;
    public const VOUCHER_MAX = 100;
    public const NOTE_MAX = 255;

    private const CONTROL = '/[\x00-\x1F\x7F]/u';

    public function __construct(private readonly AccountingAmountParser $amounts)
    {
    }

    /**
     * @param array<string, mixed> $input
     * @throws AccountingValidationException
     */
    public function parse(array $input): EntryDraft
    {
        $errors = [];
        $date = self::date($input['occurredOn'] ?? null, 'occurredOn', $errors);
        $label = self::line($input['label'] ?? null, 'label', self::LABEL_MAX, true, 'Le libellé est obligatoire.', $errors);
        $cents = $this->amounts->parse($input['amount'] ?? null);
        if ($cents === null) {
            $errors['amount'] = 'Saisissez un montant en euros, par exemple 12,50 (supérieur à zéro).';
        }
        $direction = is_string($input['direction'] ?? null) ? Direction::tryFrom($input['direction']) : null;
        $category = is_string($input['category'] ?? null) ? EntryCategory::tryFrom($input['category']) : null;
        $method = is_string($input['paymentMethod'] ?? null) ? PaymentMethod::tryFrom($input['paymentMethod']) : null;
        $errors += $direction === null ? ['direction' => 'Choisissez argent reçu ou argent dépensé.'] : [];
        $errors += $category === null ? ['category' => 'Choisissez une catégorie.'] : [];
        $errors += $method === null ? ['paymentMethod' => 'Choisissez un moyen de paiement.'] : [];
        if ($direction !== null && $category !== null && $category->direction() !== $direction) {
            $errors['category'] = 'Cette catégorie ne correspond pas au sens de l\'écriture.';
        }
        $voucher = self::line($input['voucherRef'] ?? null, 'voucherRef', self::VOUCHER_MAX, false, '', $errors);
        if ($direction === Direction::Depense && $voucher === null && !isset($errors['voucherRef'])) {
            $errors['voucherRef'] = 'Indiquez la pièce justificative (numéro de facture, de ticket…).';
        }
        $note = self::line($input['note'] ?? null, 'note', self::NOTE_MAX, false, '', $errors);

        if ($errors !== [] || $date === null || $label === null || $cents === null || $direction === null || $category === null || $method === null) {
            throw new AccountingValidationException($errors);
        }

        return new EntryDraft($date, $label, $cents, $direction, $category, $method, $voucher, $note);
    }

    /**
     * @param array<string, mixed> $input `reason` et `occurredOn`
     * @return array{0: string, 1: \DateTimeImmutable} motif, date de la contre-passation
     * @throws AccountingValidationException
     */
    public function parseReversal(array $input): array
    {
        $errors = [];
        $reason = self::line($input['reason'] ?? null, 'reason', self::NOTE_MAX, true, 'Indiquez le motif de l\'annulation.', $errors);
        $date = self::date($input['occurredOn'] ?? null, 'occurredOn', $errors);
        if ($errors !== [] || $reason === null || $date === null) {
            throw new AccountingValidationException($errors);
        }

        return [$reason, $date];
    }

    /** @param array<string, string> $errors */
    public static function date(mixed $value, string $key, array &$errors): ?\DateTimeImmutable
    {
        if (is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value && $value >= '1900-01-01' && $value <= '2999-12-31') {
                return $date;
            }
        }
        $errors[$key] = 'Saisissez une date valide.';

        return null;
    }

    /**
     * Une ligne de texte bornée, sans caractère de contrôle ; null si vide (erreur seulement si le champ est obligatoire).
     *
     * @param array<string, string> $errors
     */
    public static function line(mixed $value, string $key, int $max, bool $required, string $requiredMessage, array &$errors): ?string
    {
        if ($value !== null && !is_string($value)) {
            $errors[$key] = 'Ce champ doit être un texte.';

            return null;
        }
        $clean = trim((string) $value);
        if ($clean === '') {
            if ($required) {
                $errors[$key] = $requiredMessage;
            }

            return null;
        }
        if (mb_strlen($clean) > $max) {
            $errors[$key] = 'Ce champ ne peut pas dépasser ' . $max . ' caractères.';

            return null;
        }
        if (preg_match(self::CONTROL, $clean) === 1) {
            $errors[$key] = 'Ce champ doit tenir sur une ligne, sans caractère de contrôle.';

            return null;
        }

        return $clean;
    }
}
