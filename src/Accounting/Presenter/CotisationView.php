<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Support\MonthLabel;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Service\Dues\CotisationCell;
use App\Accounting\Service\Dues\CotisationTable;

/** Données de la page « Cotisations » (#362) : texte brut (le gabarit échappe), un libellé en clair pour chaque état (jamais la couleur seule). */
final class CotisationView
{
    /** Cotisation mensuelle par personne, préremplie (décision du propriétaire du 2026-10-16). */
    public const DEFAULT_AMOUNT = '20,00';

    private const LABELS = [
        'payee' => 'Payée',
        'dispensee' => 'Dispensée',
        'a_payer' => 'À payer',
        'a_venir' => '',
    ];

    /**
     * @param list<FiscalYear> $years du plus récent au plus ancien
     * @return array<string, mixed>
     */
    public function page(array $years, ?FiscalYear $year, ?CotisationTable $table): array
    {
        return [
            'years' => array_map(static fn (FiscalYear $y): array => [
                'label' => $y->label,
                'selected' => $year !== null && $y->id === $year->id,
                'url' => '/admin/accounting/cotisations?year=' . $y->id,
            ], $years),
            'year' => $year === null ? null : ['id' => $year->id, 'label' => $year->label, 'isOpen' => $year->isOpen()],
            'defaultAmount' => self::DEFAULT_AMOUNT,
            'dueCount' => $table?->dueCount() ?? 0,
            'months' => $table === null ? [] : array_map(static fn (\DateTimeImmutable $m): array => ['key' => $m->format('Y-m'), 'short' => MonthLabel::short($m), 'long' => MonthLabel::of($m)], $table->months),
            'rows' => $table === null ? [] : array_map(static function (array $row): array {
                $cells = [];
                foreach ($row['cells'] as $key => $cell) {
                    $cells[] = ['key' => $key, 'state' => $cell->value, 'label' => self::LABELS[$cell->value], 'isDue' => $cell === CotisationCell::APayer, 'isExempt' => $cell === CotisationCell::Dispensee];
                }

                return ['id' => $row['member']->id, 'name' => $row['member']->displayName, 'cells' => $cells];
            }, $table->rows),
        ];
    }
}
