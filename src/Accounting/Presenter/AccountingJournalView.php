<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Entity\PaymentMethod;

/**
 * Données de la page « Comptabilité » (#362) : texte brut, le gabarit échappe tout. Les montants sont mis en forme à la française, les
 * filtres du journal ne viennent que de listes blanches (une valeur inconnue est ignorée, jamais transmise).
 */
final class AccountingJournalView
{
    public function __construct(private readonly MoneyFormatter $money)
    {
    }

    /**
     * @param list<FiscalYear> $years du plus récent au plus ancien
     * @param list<AccountingEntry> $entries de l'exercice choisi, par numéro
     * @return array<string, mixed>
     */
    public function page(array $years, ?FiscalYear $year, ?LedgerTotals $totals, array $entries, string $direction, string $category): array
    {
        $directionFilter = Direction::tryFrom($direction);
        $categoryFilter = EntryCategory::tryFrom($category);

        return [
            'years' => array_map(fn (FiscalYear $y): array => [
                'id' => $y->id,
                'label' => $y->label,
                'status' => $y->status->label(),
                'selected' => $year !== null && $y->id === $year->id,
                'url' => '/admin/accounting?year=' . $y->id,
            ], $years),
            'year' => $year === null || $totals === null ? null : $this->summary($year, $totals),
            'entries' => $year === null ? [] : $this->rows($year, $entries, $directionFilter, $categoryFilter),
            'filters' => [
                'direction' => $directionFilter === null ? '' : $directionFilter->value,
                'category' => $categoryFilter === null ? '' : $categoryFilter->value,
                'active' => $directionFilter !== null || $categoryFilter !== null,
                'directions' => $this->directions(),
                'categories' => $this->categories(),
            ],
            'form' => ['directions' => $this->directions(), 'categories' => $this->categories(), 'methods' => $this->methods()],
            'next' => $this->next($years[0] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function summary(FiscalYear $year, LedgerTotals $totals): array
    {
        $balance = $year->openingBalanceCents + $totals->netCents();

        return [
            'id' => $year->id,
            'label' => $year->label,
            'status' => $year->status->label(),
            'isOpen' => $year->isOpen(),
            'canClose' => $year->isOpen(),
            'canApprove' => $year->status === FiscalYearStatus::Cloture,
            'revision' => $year->revision,
            'closing' => $year->closingBalanceCents === null ? '' : $this->money->format($year->closingBalanceCents),
            'approvedOn' => $year->approvedOn?->format('d/m/Y') ?? '',
            'period' => 'du ' . $year->startsOn->format('d/m/Y') . ' au ' . $year->endsOn->format('d/m/Y'),
            'opening' => $this->money->format($year->openingBalanceCents),
            'recettes' => $this->money->format($totals->recettesCents),
            'depenses' => $this->money->format($totals->depensesCents),
            'balance' => $this->money->format($balance),
            'balanceNegative' => $balance < 0,
        ];
    }

    /**
     * @param list<AccountingEntry> $entries
     * @return list<array<string, mixed>>
     */
    private function rows(FiscalYear $year, array $entries, ?Direction $direction, ?EntryCategory $category): array
    {
        $reversed = [];
        foreach ($entries as $entry) {
            if ($entry->reversesEntryId !== null) {
                $reversed[$entry->reversesEntryId] = true;
            }
        }
        $rows = [];
        foreach ($entries as $entry) {
            if (($direction !== null && $entry->direction !== $direction) || ($category !== null && $entry->category !== $category)) {
                continue;
            }
            $isReversal = $entry->reversesEntryId !== null;
            $isReversed = isset($reversed[$entry->id]);
            $rows[] = [
                'id' => $entry->id,
                'number' => $entry->number,
                'date' => $entry->occurredOn->format('d/m/Y'),
                'label' => $entry->label,
                'category' => $entry->category->label(),
                'direction' => $entry->direction->value,
                'directionLabel' => $entry->direction->label(),
                'amount' => $this->money->format($entry->direction === Direction::Recette ? $entry->amountCents : -$entry->amountCents, true),
                'method' => $entry->paymentMethod->label(),
                'voucher' => $entry->voucherRef ?? '',
                'note' => $entry->note ?? '',
                'isReversal' => $isReversal,
                'isReversed' => $isReversed,
                'canReverse' => $year->isOpen() && !$isReversal && !$isReversed,
            ];
        }

        return $rows;
    }

    /**
     * Proposition pour le nouvel exercice : le lendemain de la fin du plus récent, pour un an, si celui-ci est clôturé.
     *
     * @return array{startsOn: string, endsOn: string, label: string, canCreate: bool, isFirst: bool}
     */
    private function next(?FiscalYear $latest): array
    {
        if ($latest === null) {
            return ['startsOn' => '', 'endsOn' => '', 'label' => '', 'canCreate' => true, 'isFirst' => true];
        }
        $start = $latest->endsOn->modify('+1 day');
        $end = $start->modify('+1 year')->modify('-1 day');

        return [
            'startsOn' => $start->format('Y-m-d'),
            'endsOn' => $end->format('Y-m-d'),
            'label' => $start->format('Y') . '-' . $end->format('Y'),
            'canCreate' => $latest->status !== FiscalYearStatus::Ouvert,
            'isFirst' => false,
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function directions(): array
    {
        return array_map(static fn (Direction $d): array => ['value' => $d->value, 'label' => $d->label()], Direction::cases());
    }

    /** @return list<array{value: string, label: string, direction: string}> */
    private function categories(): array
    {
        return array_map(static fn (EntryCategory $c): array => ['value' => $c->value, 'label' => $c->label(), 'direction' => $c->direction()->value], EntryCategory::cases());
    }

    /** @return list<array{value: string, label: string}> */
    private function methods(): array
    {
        return array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases());
    }
}
