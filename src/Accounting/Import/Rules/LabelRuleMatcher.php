<?php

declare(strict_types=1);

namespace App\Accounting\Import\Rules;

use App\Accounting\Entity\Direction;

/**
 * Quelle règle s'applique à une ligne (#362), pure : celle du sens de la ligne dont le motif est contenu dans le libellé normalisé ; la plus
 * LONGUE l'emporte (la plus précise) ; deux règles de même longueur qui disent des choses différentes = doute, aucune règle ne s'applique.
 */
final class LabelRuleMatcher
{
    /** @param list<LabelRule> $rules */
    public function match(array $rules, Direction $direction, string $label): ?LabelRule
    {
        $normalized = LabelNormalizer::normalize($label);
        $best = [];
        $length = 0;
        foreach ($rules as $rule) {
            if ($rule->direction !== $direction || $rule->pattern === '' || !str_contains($normalized, $rule->pattern)) {
                continue;
            }
            $size = mb_strlen($rule->pattern);
            if ($size > $length) {
                $best = [$rule];
                $length = $size;
            } elseif ($size === $length) {
                $best[] = $rule;
            }
        }
        if ($best === []) {
            return null;
        }
        foreach ($best as $candidate) {
            if ($candidate->category !== $best[0]->category || $candidate->paymentMethod !== $best[0]->paymentMethod) {
                return null;
            }
        }

        return $best[0];
    }
}
