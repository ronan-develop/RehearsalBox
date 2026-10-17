<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Support\MonthLabel;
use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\LedgerTotals;

/**
 * Rapport financier d'un exercice (#362) : la page imprimable pour l'assemblée générale (art. 11) et le texte repris dans le compte rendu d'AG.
 * Texte brut : le gabarit échappe. Un exercice encore ouvert est annoncé « provisoire » : ses chiffres bougent encore.
 */
final class YearReportView
{
    public function __construct(private readonly MoneyFormatter $money)
    {
    }

    /**
     * @param list<AccountingEntry> $entries par numéro
     * @param array<int, string> $memberNames noms des comptes actifs par identifiant
     * @return array<string, mixed>
     */
    public function build(FiscalYear $year, LedgerTotals $totals, array $entries, array $memberNames, string $associationName): array
    {
        $balance = $year->openingBalanceCents + $totals->netCents();

        return [
            'associationName' => $associationName,
            'title' => 'Rapport financier — exercice ' . $year->label,
            'period' => 'du ' . $year->startsOn->format('d/m/Y') . ' au ' . $year->endsOn->format('d/m/Y'),
            'banner' => $year->isOpen() ? 'Exercice en cours : ces chiffres sont provisoires.' : null,
            'statusLine' => $year->status->label() . ($year->approvedOn !== null ? ' le ' . $year->approvedOn->format('d/m/Y') : ''),
            'opening' => $this->money->format($year->openingBalanceCents),
            'recettes' => $this->money->format($totals->recettesCents),
            'depenses' => $this->money->format($totals->depensesCents),
            'closing' => $this->money->format($year->closingBalanceCents ?? $balance),
            'closingLabel' => $year->isOpen() ? 'Solde actuel' : 'Solde final',
            'recetteLines' => $this->lines($totals, Direction::Recette),
            'depenseLines' => $this->lines($totals, Direction::Depense),
            'entries' => array_map(fn (AccountingEntry $e): array => [
                'number' => $e->number,
                'date' => $e->occurredOn->format('d/m/Y'),
                'label' => $e->label,
                'category' => $e->category->label(),
                'amount' => $this->money->format($e->direction === Direction::Recette ? $e->amountCents : -$e->amountCents, true),
                'detail' => $e->periodMonth === null ? '' : 'Cotisation de ' . ($memberNames[$e->memberUserId ?? 0] ?? 'un ancien membre') . ' pour ' . MonthLabel::of($e->periodMonth),
            ], $entries),
        ];
    }

    /** Texte à reprendre dans le rapport financier du compte rendu d'assemblée : chiffres de l'exercice, sans aucun nom. */
    public function figuresText(FiscalYear $year, LedgerTotals $totals): string
    {
        $balance = $year->openingBalanceCents + $totals->netCents();
        $final = $year->closingBalanceCents ?? $balance;
        $lines = [
            'Chiffres de l\'exercice ' . $year->label . ' (du ' . $year->startsOn->format('d/m/Y') . ' au ' . $year->endsOn->format('d/m/Y') . ')' . ($year->isOpen() ? ', provisoires : l\'exercice n\'est pas clôturé' : '') . ' :',
            'Solde d\'ouverture : ' . $this->money->format($year->openingBalanceCents),
            'Recettes : ' . $this->money->format($totals->recettesCents),
        ];
        foreach ($this->lines($totals, Direction::Recette) as $line) {
            $lines[] = '- ' . $line['label'] . ' : ' . $line['amount'];
        }
        $lines[] = 'Dépenses : ' . $this->money->format($totals->depensesCents);
        foreach ($this->lines($totals, Direction::Depense) as $line) {
            $lines[] = '- ' . $line['label'] . ' : ' . $line['amount'];
        }
        $lines[] = ($year->isOpen() ? 'Solde actuel' : 'Solde final') . ' : ' . $this->money->format($final);

        return implode("\n", $lines);
    }

    /** @return list<array{label: string, amount: string}> les catégories d'un sens, montants positifs */
    private function lines(LedgerTotals $totals, Direction $direction): array
    {
        $lines = [];
        foreach ($totals->netByCategory as $value => $net) {
            $category = EntryCategory::tryFrom($value);
            if ($category !== null && $category->direction() === $direction) {
                $lines[] = ['label' => $category->label(), 'amount' => $this->money->format(abs($net))];
            }
        }

        return $lines;
    }
}
