<?php

declare(strict_types=1);

namespace App\Accounting\Todo;

use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Service\StatementReview;
use App\Accounting\Support\MonthLabel;

/**
 * « À faire » du trésorier (#362), pur et sans base : calculé à partir de l'état du livre, rien à configurer. Pour chaque MOIS ÉCOULÉ de
 * l'exercice ouvert : le relevé (un relevé couvre le mois où il se termine), les mouvements (une écriture datée du mois, ou la mention « rien à
 * saisir ce mois-ci »), l'écart d'un relevé avec le livre. À l'échéance (le 5 du mois suivant par défaut) la tâche devient « en retard ».
 * Puis les échéances récurrentes en attente et le cycle de l'exercice (préparer la clôture 30 jours avant, clôturer, imprimer le rapport,
 * enregistrer l'approbation). Les libellés ne portent ni montant, ni nom, ni donnée bancaire (ils partent aussi par e-mail).
 */
final class TodoPlanner
{
    private const MAX_MONTHS = 25;
    private const BASE = '/admin/accounting';

    public function __construct(private readonly int $dueDay = 5)
    {
    }

    /**
     * @param list<StatementReview> $reviews relevés de l'exercice
     * @param array<string, true> $entryMonths mois (AAAA-MM) ayant au moins une écriture
     * @param array<string, true> $emptyMonths mois (AAAA-MM) déclarés « rien à saisir »
     * @return list<TodoItem> les tâches en retard d'abord, puis par échéance
     */
    public function plan(FiscalYear $year, \DateTimeImmutable $today, array $reviews, array $entryMonths, array $emptyMonths, int $pendingRecurring): array
    {
        $items = match ($year->status) {
            FiscalYearStatus::Ouvert => [...$this->monthly($year, $today, $reviews, $entryMonths, $emptyMonths), ...$this->recurring($pendingRecurring), ...$this->closing($year, $today)],
            FiscalYearStatus::Cloture => $this->afterClosing($year),
            FiscalYearStatus::Approuve => [],
        };
        usort($items, static fn (TodoItem $a, TodoItem $b): int => [$a->state === TodoState::EnRetard ? 0 : 1, $a->dueOn?->format('Y-m-d') ?? '9999']
            <=> [$b->state === TodoState::EnRetard ? 0 : 1, $b->dueOn?->format('Y-m-d') ?? '9999']);

        return $items;
    }

    /**
     * @param list<StatementReview> $reviews
     * @param array<string, true> $entryMonths
     * @param array<string, true> $emptyMonths
     * @return list<TodoItem>
     */
    private function monthly(FiscalYear $year, \DateTimeImmutable $today, array $reviews, array $entryMonths, array $emptyMonths): array
    {
        $covered = [];
        foreach ($reviews as $review) {
            if (!$review->statement->isReplaced()) {
                $covered[$review->statement->periodEnd->format('Y-m')] = true;
            }
        }
        $items = [];
        $month = $year->startsOn->modify('first day of this month');
        for ($i = 0; $i < self::MAX_MONTHS && $month <= $year->endsOn; ++$i, $month = $month->modify('+1 month')) {
            $next = $month->modify('+1 month');
            if ($today < $next) {
                break;
            }
            $key = $month->format('Y-m');
            $due = $next->modify('+' . ($this->dueDay - 1) . ' days');
            $state = $today > $due ? TodoState::EnRetard : TodoState::AFaire;
            $name = self::monthName($month);
            if (!isset($covered[$key])) {
                $items[] = new TodoItem('statement:' . $key, 'Déposer le relevé de ' . $name, 'Déposer', self::BASE . '/statements?year=' . $year->id, $state, $due);
            }
            if (!isset($entryMonths[$key]) && !isset($emptyMonths[$key])) {
                $items[] = new TodoItem('entries:' . $key, 'Saisir les mouvements de ' . $name, 'Saisir', self::BASE . '?year=' . $year->id, $state, $due);
            }
        }
        foreach ($reviews as $review) {
            if ($review->gapCents === null || $review->gapCents === 0 || $review->statement->isReplaced()) {
                continue;
            }
            $due = $review->statement->periodEnd->modify('+' . $this->dueDay . ' days');
            $items[] = new TodoItem(
                'gap:' . $review->statement->periodEnd->format('Y-m'),
                'Vérifier l\'écart du relevé du ' . $review->statement->periodStart->format('d/m/Y') . ' au ' . $review->statement->periodEnd->format('d/m/Y'),
                'Vérifier',
                self::BASE . '/statements?year=' . $year->id,
                $today > $due ? TodoState::EnRetard : TodoState::AFaire,
                $due,
            );
        }

        return $items;
    }

    /** @return list<TodoItem> */
    private function recurring(int $pending): array
    {
        if ($pending < 1) {
            return [];
        }

        return [new TodoItem('recurring', 'Confirmer les échéances récurrentes (' . $pending . ')', 'Confirmer', self::BASE . '/recurring', TodoState::AFaire, null)];
    }

    /** @return list<TodoItem> */
    private function closing(FiscalYear $year, \DateTimeImmutable $today): array
    {
        if ($today <= $year->endsOn) {
            if ($today >= $year->endsOn->modify('-30 days')) {
                return [new TodoItem('prepare-close', 'Préparer la clôture de l\'exercice ' . $year->label, 'Voir le journal', self::BASE . '?year=' . $year->id, TodoState::AFaire, $year->endsOn)];
            }

            return [];
        }
        $due = $year->endsOn->modify('+30 days');

        return [new TodoItem('close', 'Clôturer l\'exercice ' . $year->label, 'Clôturer', self::BASE . '?year=' . $year->id, $today > $due ? TodoState::EnRetard : TodoState::AFaire, $due)];
    }

    /** @return list<TodoItem> */
    private function afterClosing(FiscalYear $year): array
    {
        return [
            new TodoItem('report', 'Imprimer le rapport financier de l\'exercice ' . $year->label . ' pour la convocation de l\'assemblée générale', 'Imprimer', self::BASE . '/years/' . $year->id . '/report', TodoState::AFaire, null),
            new TodoItem('approve', 'Enregistrer l\'approbation des comptes après l\'assemblée générale', 'Enregistrer', self::BASE . '?year=' . $year->id, TodoState::AFaire, null),
        ];
    }

    private static function monthName(\DateTimeImmutable $month): string
    {
        return MonthLabel::of($month);
    }
}
