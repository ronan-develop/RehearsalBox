<?php

declare(strict_types=1);

namespace App\Accounting\Import\Suggest;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\Member;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Entity\RecurringTemplate;
use App\Accounting\Import\ImportLine;
use App\Accounting\Import\Rules\LabelNormalizer;
use App\Accounting\Import\Rules\LabelRule;
use App\Accounting\Import\Rules\LabelRuleMatcher;
use App\Accounting\Service\Recurring\RecurrenceSchedule;

/**
 * Ce que l'application PROPOSE pour une ligne (#362), pur : par ordre de précision, (1) une échéance d'un modèle récurrent (même sens, même
 * montant ou même libellé pour un montant variable, date à moins de 10 jours de l'échéance, pas encore décidée), (2) la cotisation d'un
 * adhérent (argent reçu du montant d'une cotisation, dont le libellé contient tout le nom d'UN SEUL adhérent, mois pas encore payé), (3) une
 * règle de libellé. En cas de doute (plusieurs adhérents, règles contradictoires) : aucune proposition, la ligne reste « à catégoriser ».
 */
final class LineSuggester
{
    public const DUES_CENTS = 2000;
    private const WINDOW_DAYS = 10;

    public function __construct(
        private readonly RecurrenceSchedule $schedule = new RecurrenceSchedule(),
        private readonly LabelRuleMatcher $matcher = new LabelRuleMatcher(),
    ) {
    }

    /**
     * @param list<ImportLine> $lines
     * @param list<RecurringTemplate> $templates modèles actifs
     * @param array<string, true> $decided échéances décidées, clés `idModèle|AAAA-MM-01`
     * @param list<Member> $members adhérents actifs
     * @param array<string, true> $paid cotisations déjà payées, clés `idAdhérent|AAAA-MM-01`
     * @param list<LabelRule> $rules
     * @return array<int, Suggestion> par identifiant de ligne
     */
    public function suggest(array $lines, array $templates, array $decided, array $members, array $paid, array $rules): array
    {
        $taken = [];
        $out = [];
        foreach ($lines as $line) {
            $suggestion = $this->recurring($line, $templates, $decided, $taken) ?? $this->dues($line, $members, $paid, $taken) ?? $this->rule($line, $rules);
            if ($suggestion !== null) {
                $out[$line->id] = $suggestion;
            }
        }

        return $out;
    }

    /**
     * @param list<RecurringTemplate> $templates
     * @param array<string, true> $decided
     * @param array<string, true> $taken
     */
    private function recurring(ImportLine $line, array $templates, array $decided, array &$taken): ?Suggestion
    {
        $normalized = LabelNormalizer::normalize($line->label);
        foreach ($templates as $template) {
            if ($template->direction !== $line->direction) {
                continue;
            }
            $templateLabel = LabelNormalizer::normalize($template->label);
            $sameAmount = $template->amountCents === $line->amountCents;
            $sameLabel = $template->amountCents === null && mb_strlen($templateLabel) >= 3 && str_contains($normalized, $templateLabel);
            if (!$sameAmount && !$sameLabel) {
                continue;
            }
            $from = $line->occurredOn->modify('-' . self::WINDOW_DAYS . ' days');
            $to = $line->occurredOn->modify('+' . self::WINDOW_DAYS . ' days');
            foreach ($this->schedule->periodsBetween($template, $from, $to) as $period) {
                $key = $template->id . '|' . $period->format('Y-m-d');
                if (isset($decided[$key]) || isset($taken[$key])) {
                    continue;
                }
                $taken[$key] = true;

                return new Suggestion('Échéance du modèle « ' . $template->label . ' »', $template->category, $template->paymentMethod, $template->voucherRef, $key, null, null, $template->label);
            }
        }

        return null;
    }

    /**
     * @param list<Member> $members
     * @param array<string, true> $paid
     * @param array<string, true> $taken
     */
    private function dues(ImportLine $line, array $members, array $paid, array &$taken): ?Suggestion
    {
        if ($line->direction !== Direction::Recette || $line->amountCents !== self::DUES_CENTS) {
            return null;
        }
        $normalized = ' ' . LabelNormalizer::normalize($line->label) . ' ';
        $month = $line->occurredOn->modify('first day of this month');
        $found = [];
        foreach ($members as $member) {
            $tokens = array_filter(explode(' ', LabelNormalizer::normalize($member->displayName)), static fn (string $t): bool => mb_strlen($t) >= 3);
            if ($tokens === [] || array_filter($tokens, static fn (string $t): bool => !str_contains($normalized, ' ' . $t . ' ')) !== []) {
                continue;
            }
            $found[] = $member;
        }
        if (count($found) !== 1) {
            return null;
        }
        $key = $found[0]->id . '|' . $month->format('Y-m-d');
        if (isset($paid[$key]) || isset($taken['d' . $key])) {
            return null;
        }
        $taken['d' . $key] = true;

        return new Suggestion('Cotisation de ' . $found[0]->displayName, EntryCategory::Cotisations, PaymentMethod::Virement, null, null, $found[0]->id, $month->format('Y-m'), 'Cotisation');
    }

    /** @param list<LabelRule> $rules */
    private function rule(ImportLine $line, array $rules): ?Suggestion
    {
        $rule = $this->matcher->match($rules, $line->direction, $line->label);

        return $rule === null ? null : new Suggestion('Règle retenue (« ' . $rule->pattern . ' »)', $rule->category, $rule->paymentMethod);
    }
}
