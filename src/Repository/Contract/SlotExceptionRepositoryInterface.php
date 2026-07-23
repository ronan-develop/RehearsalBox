<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\SlotException;

interface SlotExceptionRepositoryInterface
{
    public function findById(int $id): ?SlotException;

    /** @return list<SlotException> */
    public function findLiberatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    public function createLiberation(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $releasedByUserId,
        ?string $reason,
    ): SlotException;

    /**
     * Revendique une occurrence libérée. Retourne false (rowCount=0) si déjà
     * revendiquée entre-temps — jamais d'exception pour ce cas, c'est un
     * résultat métier normal (cf. plan §0.2/§10.5).
     */
    public function claim(int $exceptionId, int $groupId, int $userId): bool;
}
