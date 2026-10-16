<?php

declare(strict_types=1);

namespace App\Accounting\Service\Recurring;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\Periodicity;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Service\AccountingAmountParser;
use App\Accounting\Service\EntryInputPolicy;

/** Saisie d'un modèle récurrent (#362) : validée AVANT la base, toutes les erreurs remontées ensemble par champ. */
final class TemplateInputPolicy
{
    public function __construct(private readonly AccountingAmountParser $amounts)
    {
    }

    /**
     * @param array<string, mixed> $input `label`, `direction`, `category`, `paymentMethod`, `amount` (vide = variable), `voucherRef`,
     *                                    `periodicity`, `dueDay` (1 à 28), `firstMonth` (AAAA-MM), `isActive`
     * @throws AccountingValidationException
     */
    public function parse(array $input): TemplateDraft
    {
        $errors = [];
        $label = EntryInputPolicy::line($input['label'] ?? null, 'label', EntryInputPolicy::LABEL_MAX, true, 'Le libellé est obligatoire.', $errors);
        $direction = is_string($input['direction'] ?? null) ? Direction::tryFrom($input['direction']) : null;
        $category = is_string($input['category'] ?? null) ? EntryCategory::tryFrom($input['category']) : null;
        $method = is_string($input['paymentMethod'] ?? null) ? PaymentMethod::tryFrom($input['paymentMethod']) : null;
        $periodicity = is_string($input['periodicity'] ?? null) ? Periodicity::tryFrom($input['periodicity']) : null;
        $errors += $direction === null ? ['direction' => 'Choisissez argent reçu ou argent dépensé.'] : [];
        $errors += $category === null ? ['category' => 'Choisissez une catégorie.'] : [];
        $errors += $method === null ? ['paymentMethod' => 'Choisissez un moyen de paiement.'] : [];
        $errors += $periodicity === null ? ['periodicity' => 'Choisissez la périodicité.'] : [];
        if ($direction !== null && $category !== null && $category->direction() !== $direction) {
            $errors['category'] = 'Cette catégorie ne correspond pas au sens de l\'écriture.';
        }
        $amount = null;
        $rawAmount = $input['amount'] ?? null;
        if ($rawAmount !== null && $rawAmount !== '') {
            $amount = $this->amounts->parse($rawAmount);
            if ($amount === null) {
                $errors['amount'] = 'Saisissez un montant en euros, par exemple 166,00, ou laissez vide s\'il varie.';
            }
        }
        $voucher = EntryInputPolicy::line($input['voucherRef'] ?? null, 'voucherRef', EntryInputPolicy::VOUCHER_MAX, false, '', $errors);
        $day = self::day($input['dueDay'] ?? null);
        if ($day === null) {
            $errors['dueDay'] = 'Le jour d\'échéance est compris entre 1 et 28.';
        }
        $firstMonth = self::month($input['firstMonth'] ?? null);
        if ($firstMonth === null) {
            $errors['firstMonth'] = 'Saisissez le premier mois, par exemple 2025-10.';
        }
        if ($errors !== [] || $label === null || $direction === null || $category === null || $method === null || $periodicity === null || $day === null || $firstMonth === null) {
            throw new AccountingValidationException($errors);
        }

        return new TemplateDraft($label, $direction, $category, $method, $amount, $voucher, $periodicity, $day, $firstMonth, self::flag($input['isActive'] ?? true));
    }

    private static function day(mixed $value): ?int
    {
        $text = is_int($value) ? (string) $value : $value;
        if (is_string($text) && preg_match('/\A(?:[1-9]|1[0-9]|2[0-8])\z/D', $text) === 1) {
            return (int) $text;
        }

        return null;
    }

    private static function month(mixed $value): ?\DateTimeImmutable
    {
        if (is_string($value) && preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $value) === 1 && $value >= '1900-01' && $value <= '2999-12') {
            return new \DateTimeImmutable($value . '-01');
        }

        return null;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
