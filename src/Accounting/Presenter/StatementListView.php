<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Entity\FiscalYear;
use App\Accounting\Service\StatementReview;

/** Données de la page « Relevés » (#362) : texte brut (le gabarit échappe), montants à la française, écart expliqué en clair. */
final class StatementListView
{
    public function __construct(private readonly MoneyFormatter $money)
    {
    }

    /**
     * @param list<FiscalYear> $years du plus récent au plus ancien
     * @param list<StatementReview> $reviews
     * @return array<string, mixed>
     */
    public function page(array $years, ?FiscalYear $year, array $reviews): array
    {
        return [
            'years' => array_map(static fn (FiscalYear $y): array => [
                'label' => $y->label,
                'selected' => $year !== null && $y->id === $year->id,
                'url' => '/admin/accounting/statements?year=' . $y->id,
            ], $years),
            'year' => $year === null ? null : ['id' => $year->id, 'label' => $year->label, 'isOpen' => $year->isOpen()],
            'statements' => array_map(fn (StatementReview $r): array => $this->row($r, $year?->isOpen() ?? false), $reviews),
        ];
    }

    /** @return array<string, mixed> */
    private function row(StatementReview $review, bool $yearOpen): array
    {
        $s = $review->statement;
        $gap = $review->gapCents;

        return [
            'id' => $s->id,
            'name' => $s->originalName,
            'period' => 'du ' . $s->periodStart->format('d/m/Y') . ' au ' . $s->periodEnd->format('d/m/Y'),
            'size' => self::size($s->sizeBytes),
            'uploadedOn' => $s->uploadedAt->format('d/m/Y'),
            'balance' => $s->closingBalanceCents === null ? '' : $this->money->format($s->closingBalanceCents),
            'ledger' => $this->money->format($review->ledgerBalanceCents),
            'gap' => $gap === null ? '' : $this->money->format($gap, true),
            'gapState' => $gap === null ? 'none' : ($gap === 0 ? 'ok' : 'gap'),
            'isReplaced' => $s->isReplaced(),
            'canReplace' => $yearOpen && !$s->isReplaced(),
            'downloadUrl' => '/admin/accounting/statements/' . $s->id . '/file',
        ];
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? number_format($bytes / (1024 * 1024), 1, ',', "\u{a0}") . "\u{a0}Mo"
            : number_format(max(1, (int) round($bytes / 1024)), 0, ',', "\u{a0}") . "\u{a0}Ko";
    }
}
