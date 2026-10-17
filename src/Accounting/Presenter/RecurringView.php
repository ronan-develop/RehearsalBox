<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Entity\Direction;
use App\Accounting\Support\EuroText;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\Periodicity;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Service\Recurring\PendingOccurrence;

/** Données de la page « Écritures récurrentes » (#362) : texte brut (le gabarit échappe), montants à la française. */
final class RecurringView
{
    public function __construct(private readonly MoneyFormatter $money)
    {
    }

    /**
     * @param list<PendingOccurrence> $pending
     * @param list<RecurringTemplate> $templates
     * @return array<string, mixed>
     */
    public function page(array $pending, array $templates, bool $yearOpen): array
    {
        return [
            'yearOpen' => $yearOpen,
            'pending' => array_map(fn (PendingOccurrence $p): array => [
                'templateId' => $p->template->id,
                'period' => $p->period->format('Y-m-d'),
                'label' => $p->template->label,
                'dueOn' => $p->dueOn->format('d/m/Y'),
                'dueIso' => $p->dueOn->format('Y-m-d'),
                'direction' => $p->template->direction->value,
                'amount' => $p->template->amountCents === null ? '' : $this->money->format($p->template->amountCents),
                'isVariable' => $p->template->isVariable(),
                'voucher' => $p->template->voucherRef ?? '',
                'needsVoucher' => $p->template->direction === Direction::Depense && ($p->template->voucherRef ?? '') === '',
            ], $pending),
            'templates' => array_map(fn (RecurringTemplate $t): array => [
                'id' => $t->id,
                'label' => $t->label,
                'direction' => $t->direction->value,
                'directionLabel' => $t->direction->label(),
                'category' => $t->category->value,
                'categoryLabel' => $t->category->label(),
                'method' => $t->paymentMethod->value,
                'methodLabel' => $t->paymentMethod->label(),
                'amount' => $t->amountCents === null ? 'Variable' : $this->money->format($t->amountCents),
                'amountInput' => $t->amountCents === null ? '' : EuroText::plain($t->amountCents),
                'voucher' => $t->voucherRef ?? '',
                'periodicity' => $t->periodicity->value,
                'periodicityLabel' => $t->periodicity->label(),
                'dueDay' => $t->dueDay,
                'firstMonth' => $t->firstMonth->format('Y-m'),
                'isActive' => $t->isActive,
            ], $templates),
            'form' => [
                'directions' => array_map(static fn (Direction $d): array => ['value' => $d->value, 'label' => $d->label()], Direction::cases()),
                'categories' => array_map(static fn (EntryCategory $c): array => ['value' => $c->value, 'label' => $c->label(), 'direction' => $c->direction()->value], EntryCategory::cases()),
                'methods' => array_map(static fn (PaymentMethod $m): array => ['value' => $m->value, 'label' => $m->label()], PaymentMethod::cases()),
                'periodicities' => array_map(static fn (Periodicity $p): array => ['value' => $p->value, 'label' => $p->label()], Periodicity::cases()),
                'firstMonth' => date('Y-m'),
            ],
        ];
    }
}
