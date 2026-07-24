<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\RecurringSlot;
use App\Entity\SlotException;

interface AvailabilityServiceInterface
{
    /** @return list<RecurringSlot> Créneaux actifs appartenant à d'autres groupes que ceux de $userId. */
    public function findRequestableSlotsFor(int $userId): array;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si l'utilisateur courant n'appartient pas à $groupId
     * @return list<SlotException>
     */
    public function findPendingForHolderGroup(int $groupId, int $userId): array;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si l'utilisateur courant n'appartient pas à $groupId
     * @return list<SlotException>
     */
    public function findByRequestingGroup(int $groupId, int $userId): array;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas à $requestingGroupId
     */
    public function request(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $requestingGroupId,
        int $userId,
        ?string $reason,
    ): SlotException;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe titulaire du créneau
     * @throws \App\Service\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà répondue
     */
    public function respond(int $exceptionId, bool $accepted, int $userId): SlotException;
}
