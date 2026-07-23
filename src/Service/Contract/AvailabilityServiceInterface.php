<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\SlotException;

interface AvailabilityServiceInterface
{
    /** @return list<SlotException> */
    public function findLiberatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si l'utilisateur courant n'appartient pas à $groupId
     * @throws \App\Service\Exception\SlotAlreadyClaimedException si l'exception est inconnue ou déjà revendiquée
     */
    public function claim(int $exceptionId, int $groupId, int $userId): SlotException;
}
