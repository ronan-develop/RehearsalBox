<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RecurringSlot;
use App\Entity\RequestableSlot;
use App\Entity\SlotException;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Repository\Contract\SlotExceptionRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Exception\RequestAlreadyRespondedException;

final class AvailabilityService implements AvailabilityServiceInterface
{
    public function __construct(
        private readonly SlotExceptionRepositoryInterface $slotExceptionRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly RecurringSlotRepositoryInterface $recurringSlotRepository,
    ) {
    }

    public function findRequestableSlotsFor(int $userId): array
    {
        $ownGroupIds = array_map(
            static fn ($group) => $group->id(),
            $this->groupRepository->findByMember($userId),
        );

        $otherGroupsSlots = array_filter(
            $this->recurringSlotRepository->findAllActive(),
            static fn (RecurringSlot $slot) => !in_array($slot->groupId(), $ownGroupIds, true),
        );

        return array_values(array_map(
            function (RecurringSlot $slot): RequestableSlot {
                $group = $this->groupRepository->findById($slot->groupId());
                \assert($group !== null);

                return new RequestableSlot($slot, $group->name());
            },
            $otherGroupsSlots,
        ));
    }

    public function findPendingForHolderGroup(int $groupId, int $userId): array
    {
        if (!$this->groupRepository->isMember($groupId, $userId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->slotExceptionRepository->findPendingForHolderGroup($groupId);
    }

    public function findByRequestingGroup(int $groupId, int $userId): array
    {
        if (!$this->groupRepository->isMember($groupId, $userId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->slotExceptionRepository->findByRequestingGroup($groupId);
    }

    public function request(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $requestingGroupId,
        int $userId,
        ?string $reason,
    ): SlotException {
        // Appartenance vérifiée côté serveur à partir de l'utilisateur en
        // session, jamais déduite du payload client (IDOR) — un groupId
        // valide fourni par un attaquant ne suffit pas.
        if (!$this->groupRepository->isMember($requestingGroupId, $userId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->slotExceptionRepository->createRequest(
            $recurringSlotId,
            $occurrenceDate,
            $requestingGroupId,
            $userId,
            $reason,
        );
    }

    public function respond(int $exceptionId, bool $accepted, int $userId): SlotException
    {
        $exception = $this->slotExceptionRepository->findById($exceptionId);
        if ($exception === null) {
            throw new RequestAlreadyRespondedException('Cette demande n’existe plus.');
        }

        $slot = $this->recurringSlotRepository->findById($exception->recurringSlotId());
        \assert($slot !== null);

        // IDOR inversé par rapport à l'ancien claim() : c'est le groupe
        // TITULAIRE du créneau (déduit du recurring_slot serveur, jamais
        // d'un paramètre client) qui répond à la demande du groupe
        // demandeur — pas l'inverse.
        if (!$this->groupRepository->isMember($slot->groupId(), $userId)) {
            throw new AccessDeniedException("Vous n'appartenez pas au groupe titulaire de ce créneau.");
        }

        if (!$this->slotExceptionRepository->respond($exceptionId, $accepted, $userId)) {
            throw new RequestAlreadyRespondedException('Cette demande a déjà reçu une réponse.');
        }

        $responded = $this->slotExceptionRepository->findById($exceptionId);
        \assert($responded !== null);

        return $responded;
    }
}
