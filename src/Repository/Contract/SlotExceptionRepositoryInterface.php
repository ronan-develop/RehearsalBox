<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\SlotException;

interface SlotExceptionRepositoryInterface
{
    public function findById(int $id): ?SlotException;

    /** @return list<SlotException> Demandes en_attente ciblant les créneaux dont $groupId est titulaire. */
    public function findPendingForHolderGroup(int $groupId): array;

    /** @return list<SlotException> Demandes envoyées par $groupId, tous statuts confondus. */
    public function findByRequestingGroup(int $groupId): array;

    public function createRequest(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $requestedByGroupId,
        int $requestedByUserId,
        ?string $reason,
    ): SlotException;

    /**
     * Accepte ou refuse une demande en_attente. Retourne false (rowCount=0)
     * si déjà répondue entre-temps — jamais d'exception pour ce cas, c'est
     * un résultat métier normal (cf. plan §0.2/§10.5).
     */
    public function respond(int $exceptionId, bool $accepted, int $respondedByUserId): bool;

    /**
     * Modifie la date d'occurrence et/ou la raison d'une demande en_attente.
     * Retourne false si la demande n'existe plus ou a déjà été traitée.
     */
    public function update(int $exceptionId, \DateTimeImmutable $occurrenceDate, ?string $reason): bool;

    /**
     * Supprime une demande en_attente. Retourne false si elle n'existe plus
     * ou a déjà été traitée.
     */
    public function delete(int $exceptionId): bool;
}
