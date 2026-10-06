<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RecurringSlot;
use App\Entity\SlotException;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\RecurringSlotRepositoryInterface;
use App\Repository\Contract\SlotExceptionRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AvailabilityServiceInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use App\Repository\Exception\DuplicateOccurrenceException;
use App\Service\Exception\AvailabilityValidationException;
use App\Service\Exception\RequestAlreadyRespondedException;
use App\Service\Exception\RequestChangedException;

final class AvailabilityService implements AvailabilityServiceInterface
{
    /** Taille de la colonne `request_reason` : au-delà, la base refuse (erreur 500 sans cette borne). */
    private const MAX_REASON_LENGTH = 255;

    public function __construct(
        private readonly SlotExceptionRepositoryInterface $slotExceptionRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly RecurringSlotRepositoryInterface $recurringSlotRepository,
        private readonly ClockInterface $clock = new NativeClock(),
    ) {
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

    public function findArchivedForGroup(int $groupId, int $userId): array
    {
        if (!$this->groupRepository->isMember($groupId, $userId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->slotExceptionRepository->findArchivedForGroup($groupId);
    }

    public function respond(int $exceptionId, bool $accepted, int $userId, \DateTimeImmutable $seenOccurrenceDate): SlotException
    {
        $exception = $this->slotExceptionRepository->findById($exceptionId);
        if ($exception === null) {
            throw $this->accessDenied();
        }

        $slot = $this->recurringSlotRepository->findById($exception->recurringSlotId());
        \assert($slot !== null);

        // IDOR inversé par rapport à l'ancien claim() : c'est le groupe
        // TITULAIRE du créneau (déduit du recurring_slot serveur, jamais
        // d'un paramètre client) qui répond à la demande du groupe
        // demandeur — pas l'inverse.
        if (!$this->groupRepository->isMember($slot->groupId(), $userId)) {
            throw $this->accessDenied();
        }

        // Un membre du groupe demandeur ne répond pas à sa propre demande, même
        // s'il appartient aussi au groupe titulaire : c'est un échange entre deux groupes.
        if ($this->groupRepository->isMember($exception->requestedByGroupId(), $userId)) {
            throw $this->accessDenied();
        }

        // La date que le titulaire a vue fait partie de la condition atomique : si le demandeur l'a changée entre-temps,
        // l'acceptation ne vaut pas pour une autre date que celle affichée.
        if (!$this->slotExceptionRepository->respond($exceptionId, $accepted, $userId, $seenOccurrenceDate)) {
            $current = $this->slotExceptionRepository->findById($exceptionId);
            if ($current !== null && $current->isEnAttente()) {
                throw new RequestChangedException();
            }

            throw new RequestAlreadyRespondedException('Cette demande a déjà reçu une réponse.');
        }

        $responded = $this->slotExceptionRepository->findById($exceptionId);
        \assert($responded !== null);

        return $responded;
    }

    public function updateRequest(int $exceptionId, \DateTimeImmutable $occurrenceDate, ?string $reason, int $userId): SlotException
    {
        $exception = $this->slotExceptionRepository->findById($exceptionId);
        if ($exception === null) {
            throw $this->accessDenied();
        }

        // IDOR : seul le groupe DEMANDEUR (A), déduit de l'exception en base
        // et jamais d'un paramètre client, peut modifier sa propre demande.
        if (!$this->groupRepository->isMember($exception->requestedByGroupId(), $userId)) {
            throw $this->accessDenied();
        }

        $slot = $this->recurringSlotRepository->findById($exception->recurringSlotId());
        \assert($slot !== null);
        $this->assertValidRequest($slot, $occurrenceDate, $reason);

        // Conflit d'unicité (une autre demande occupe déjà cette date) : DuplicateOccurrenceException, traduite en 409 par le Kernel.
        if (!$this->slotExceptionRepository->update($exceptionId, $occurrenceDate, $reason)) {
            throw new RequestAlreadyRespondedException('Cette demande a déjà été traitée.');
        }

        $updated = $this->slotExceptionRepository->findById($exceptionId);
        \assert($updated !== null);

        return $updated;
    }

    public function cancelRequest(int $exceptionId, int $userId): void
    {
        $exception = $this->slotExceptionRepository->findById($exceptionId);
        if ($exception === null) {
            throw $this->accessDenied();
        }

        if (!$this->groupRepository->isMember($exception->requestedByGroupId(), $userId)) {
            throw $this->accessDenied();
        }

        if (!$this->slotExceptionRepository->delete($exceptionId)) {
            throw new RequestAlreadyRespondedException('Cette demande a déjà été traitée.');
        }
    }

    /**
     * Une demande vise une occurrence du créneau : même jour de semaine, jamais dans le passé, motif tenant dans la colonne.
     *
     * @throws AvailabilityValidationException
     */
    private function assertValidRequest(RecurringSlot $slot, \DateTimeImmutable $occurrenceDate, ?string $reason): void
    {
        $errors = [];
        if ((int) $occurrenceDate->format('N') - 1 !== $slot->weekday()->value) {
            $errors['occurrenceDate'] = 'La date ne correspond pas au jour de ce créneau.';
        } elseif ($occurrenceDate < $this->clock->now()->setTime(0, 0)) {
            $errors['occurrenceDate'] = 'La date ne peut pas être dans le passé.';
        }
        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $errors['reason'] = 'Le motif ne peut pas dépasser ' . self::MAX_REASON_LENGTH . ' caractères.';
        }

        if ($errors !== []) {
            throw new AvailabilityValidationException($errors);
        }
    }

    /**
     * Réponse unique pour une demande interdite ET pour une demande inexistante :
     * l'existence d'un identifiant ne doit pas se déduire de la réponse (IDOR, #118).
     */
    private function accessDenied(): AccessDeniedException
    {
        return new AccessDeniedException('Accès refusé.');
    }
}
