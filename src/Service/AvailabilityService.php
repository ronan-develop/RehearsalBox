<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SlotException;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\SlotExceptionRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Exception\SlotAlreadyClaimedException;

final class AvailabilityService implements AvailabilityServiceInterface
{
    public function __construct(
        private readonly SlotExceptionRepositoryInterface $slotExceptionRepository,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    public function findLiberatedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->slotExceptionRepository->findLiberatedBetween($from, $to);
    }

    public function claim(int $exceptionId, int $groupId, int $userId): SlotException
    {
        // Appartenance vérifiée côté serveur à partir de l'utilisateur en
        // session, jamais déduite du payload client (IDOR, cf. plan §10.5) —
        // un groupId valide fourni par un attaquant ne suffit pas.
        if (!$this->groupRepository->isMember($groupId, $userId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        if (!$this->slotExceptionRepository->claim($exceptionId, $groupId, $userId)) {
            throw new SlotAlreadyClaimedException('Ce créneau a déjà été revendiqué ou n’existe plus.');
        }

        $claimed = $this->slotExceptionRepository->findById($exceptionId);
        \assert($claimed !== null);

        return $claimed;
    }
}
