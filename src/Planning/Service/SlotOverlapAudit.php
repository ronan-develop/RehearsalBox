<?php

declare(strict_types=1);

namespace App\Planning\Service;

use App\Planning\Entity\RecurringSlot;

/**
 * État des lieux des créneaux fixes qui se chevauchent (#263) : le local devient exclusif, mais des chevauchements ont pu être
 * saisis avant la règle. Fonction pure (aucune base) ; le calcul d'intersection est celui du créneau (RecurringSlot::overlaps).
 */
final class SlotOverlapAudit
{
    /**
     * @param list<RecurringSlot> $slots
     *
     * @return list<array{RecurringSlot, RecurringSlot}> chaque paire une seule fois, dans l'ordre des identifiants
     */
    public function pairs(array $slots): array
    {
        $active = array_values(array_filter($slots, static fn (RecurringSlot $slot): bool => $slot->isActive()));
        usort($active, static fn (RecurringSlot $a, RecurringSlot $b): int => $a->id() <=> $b->id());

        $pairs = [];
        foreach ($active as $i => $first) {
            foreach (array_slice($active, $i + 1) as $second) {
                if ($first->weekday() === $second->weekday() && $first->overlaps($second->startTime(), $second->endTime())) {
                    $pairs[] = [$first, $second];
                }
            }
        }

        return $pairs;
    }
}
