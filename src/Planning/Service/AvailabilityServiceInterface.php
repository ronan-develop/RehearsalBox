<?php

declare(strict_types=1);

namespace App\Planning\Service;

use App\Planning\Entity\SlotException;

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
     * @throws \App\Security\Exception\AccessDeniedException si l'utilisateur courant n'appartient pas à $groupId
     * @return list<SlotException> Exceptions déjà traitées, titulaire ou demandeur confondus.
     */
    public function findArchivedForGroup(int $groupId, int $userId): array;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe titulaire du créneau
     * @throws \App\Planning\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà répondue
     */
    /**
     * @param \DateTimeImmutable $seenOccurrenceDate date que le titulaire a vue (#221) : l'acceptation ne vaut que pour elle
     *
     * @throws \App\Planning\Exception\RequestChangedException le demandeur a changé la date entre-temps
     */
    public function respond(int $exceptionId, bool $accepted, int $userId, \DateTimeImmutable $seenOccurrenceDate): SlotException;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe demandeur de l'exception
     * @throws \App\Planning\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà traitée
     */
    /**
     * Demande l'occurrence d'un créneau fixe d'un AUTRE groupe, en entier ou sur une plage comprise dans ce créneau (#263).
     *
     * @throws \App\Security\Exception\AccessDeniedException créneau inconnu ou supprimé, son propre créneau, ou $userId hors du groupe demandeur
     * @throws \App\Planning\Exception\AvailabilityValidationException date, motif ou plage invalides (erreurs par champ)
     * @throws \App\Planning\Exception\DuplicateOccurrenceException cette date est déjà demandée sur ce créneau
     */
    public function createRequest(
        int $recurringSlotId,
        int $requestedByGroupId,
        \DateTimeImmutable $occurrenceDate,
        ?string $reason,
        int $userId,
        ?string $startTime = null,
        ?string $endTime = null,
    ): SlotException;

    public function updateRequest(int $exceptionId, \DateTimeImmutable $occurrenceDate, ?string $reason, int $userId): SlotException;

    /**
     * @throws \App\Security\Exception\AccessDeniedException si $userId n'appartient pas au groupe demandeur de l'exception
     * @throws \App\Planning\Exception\RequestAlreadyRespondedException si l'exception est inconnue ou déjà traitée
     */
    public function cancelRequest(int $exceptionId, int $userId): void;
}
