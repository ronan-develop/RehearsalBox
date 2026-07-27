<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\SlotException;

interface AvailabilityServiceInterface
{
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
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe titulaire du créneau
     * @throws \App\Service\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà répondue
     */
    public function respond(int $exceptionId, bool $accepted, int $userId): SlotException;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe demandeur de l'exception
     * @throws \App\Service\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà traitée
     */
    public function updateRequest(int $exceptionId, \DateTimeImmutable $occurrenceDate, ?string $reason, int $userId): SlotException;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe demandeur de l'exception
     * @throws \App\Service\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà traitée
     */
    public function cancelRequest(int $exceptionId, int $userId): void;
}
