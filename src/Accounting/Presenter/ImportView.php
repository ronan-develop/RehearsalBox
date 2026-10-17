<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\Member;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Import\ImportLine;
use App\Accounting\Import\Parse\CsvProfile;
use App\Accounting\Import\Rules\LabelRule;
use App\Accounting\Import\Suggest\Suggestion;

/** Données des pages d'import et de règles (#362) : texte brut (le gabarit échappe), montants à la française, propositions présentées comme des AIDES. */
final class ImportView
{
    public function __construct(private readonly MoneyFormatter $money)
    {
    }

    /**
     * @param list<ImportLine> $lines
     * @param array<int, Suggestion> $suggestions
     * @param list<Member> $members
     * @return array<string, mixed>
     */
    public function page(array $lines, array $suggestions, array $members, ?CsvProfile $profile): array
    {
        return [
            'profile' => self::profile($profile),
            'members' => array_map(static fn (Member $m): array => ['value' => (string) $m->id, 'label' => $m->displayName], $members),
            'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
            'lines' => array_map(fn (ImportLine $l): array => $this->row($l, $suggestions[$l->id] ?? null), $lines),
        ];
    }

    /**
     * @param list<LabelRule> $rules
     * @return array<string, mixed>
     */
    public function rules(array $rules): array
    {
        return [
            'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
            'rules' => array_map(fn (LabelRule $r): array => [
                'id' => $r->id,
                'pattern' => $r->pattern,
                'direction' => $r->direction->value,
                'directionLabel' => $r->direction->label(),
                'category' => $r->category->value,
                'method' => $r->paymentMethod->value,
                'categories' => $this->categories($r->direction),
            ], $rules),
        ];
    }

    /** @return array<string, mixed> */
    private function row(ImportLine $line, ?Suggestion $suggestion): array
    {
        $isExpense = $line->direction === Direction::Depense;
        $category = $suggestion === null ? null : $suggestion->category;
        $method = $suggestion === null ? null : $suggestion->paymentMethod;

        return [
            'id' => $line->id,
            'date' => $line->occurredOn->format('d/m/Y'),
            'label' => $line->label,
            'amount' => $this->money->format($isExpense ? -$line->amountCents : $line->amountCents, true),
            'direction' => $line->direction->value,
            'directionLabel' => $line->direction->label(),
            'needsVoucher' => $isExpense,
            'categories' => $this->categories($line->direction),
            'source' => $suggestion->source ?? '',
            'category' => $category === null ? '' : $category->value,
            'method' => ($method ?? PaymentMethod::Virement)->value,
            'voucher' => $suggestion->voucherRef ?? '',
            'occurrence' => $suggestion->occurrence ?? '',
            'memberUserId' => ($suggestion->memberUserId ?? null) === null ? '' : (string) $suggestion->memberUserId,
            'periodMonth' => $suggestion->periodMonth ?? '',
            'proposedLabel' => $suggestion->label ?? '',
            'checked' => $category !== null && (!$isExpense || ($suggestion->voucherRef ?? '') !== ''),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function categories(Direction $direction): array
    {
        return array_values(array_map(
            static fn (EntryCategory $c): array => ['value' => $c->value, 'label' => $c->label()],
            array_filter(EntryCategory::cases(), static fn (EntryCategory $c): bool => $c->direction() === $direction),
        ));
    }

    /** @return array<string, string> */
    private static function profile(?CsvProfile $profile): array
    {
        $names = [';' => 'semicolon', ',' => 'comma', "\t" => 'tab'];

        return [
            'delimiter' => $names[$profile->delimiter ?? ';'] ?? 'semicolon',
            'encoding' => $profile->encoding ?? 'UTF-8',
            'skipRows' => (string) ($profile->skipRows ?? 1),
            'dateColumn' => (string) ($profile->dateColumn ?? 1),
            'labelColumn' => (string) ($profile->labelColumn ?? 2),
            'amountMode' => $profile->amountMode ?? CsvProfile::SIGNED,
            'amountColumn' => (string) ($profile === null || $profile->amountColumn === 0 ? 3 : $profile->amountColumn),
            'debitColumn' => (string) ($profile === null || $profile->debitColumn === 0 ? 3 : $profile->debitColumn),
            'creditColumn' => (string) ($profile === null || $profile->creditColumn === 0 ? 4 : $profile->creditColumn),
            'decimalSeparator' => $profile->decimalSeparator ?? ',',
            'dateFormat' => $profile->dateFormat ?? 'd/m/Y',
        ];
    }
}
