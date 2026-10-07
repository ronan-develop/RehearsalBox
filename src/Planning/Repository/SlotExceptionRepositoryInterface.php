<?php

declare(strict_types=1);

namespace App\Planning\Repository;

use App\Planning\Entity\SlotException;
use App\Planning\Exception\DuplicateOccurrenceException;

interface SlotExceptionRepositoryInterface
{
    public function findById(int $id): ?SlotException;

    /** @return list<SlotException> Demandes en_attente ciblant les créneaux dont $groupId est titulaire. */
    public function findPendingForHolderGroup(int $groupId): array;

    /** @return list<SlotException> Demandes envoyées par $groupId, tous statuts confondus. */
    public function findByRequestingGroup(int $groupId): array;

    /**
     * @return list<SlotException> Exceptions déjà traitées (statut != en_attente) où
     *         $groupId est titulaire du créneau ou demandeur, tous confondus, triées
     *         par date d'occurrence décroissante (les plus récentes en premier).
     */
    public function findArchivedForGroup(int $groupId): array;

    /**
     * @return list<SlotException> Exceptions acceptées dont l'occurrence tombe dans la
     *         semaine en cours (lundi-dimanche) — cf. planning "créneaux occasionnels" #34.
     */
    public function findAcceptedForCurrentWeek(): array;

    /**
     * @param string|null $startTime début de la plage demandée (#263), null = tout le créneau du titulaire
     * @param string|null $endTime   fin de la plage (les deux bornes ensemble ; la base refuse une plage incomplète ou à l'envers)
     */
    public function createRequest(
        int $recurringSlotId,
        \DateTimeImmutable $occurrenceDate,
        int $requestedByGroupId,
        int $requestedByUserId,
        ?string $reason,
        ?string $startTime = null,
        ?string $endTime = null,
    ): SlotException;

    /**
     * Accepte ou refuse une demande en_attente. Retourne false (rowCount=0)
     * si déjà répondue entre-temps — jamais d'exception pour ce cas, c'est
     * un résultat métier normal (cf. plan §0.2/§10.5).
     *
     * @param \DateTimeImmutable|null $expectedOccurrenceDate date que le titulaire a vue (#221) : si fournie, la réponse ne
     *                                                       s'applique que si la demande la porte encore (condition atomique)
     */
    public function respond(int $exceptionId, bool $accepted, int $respondedByUserId, ?\DateTimeImmutable $expectedOccurrenceDate = null): bool;

    /**
     * Modifie la date d'occurrence et/ou la raison d'une demande en_attente.
     * Retourne false si la demande n'existe plus ou a déjà été traitée.
     *
     * @throws DuplicateOccurrenceException une autre demande occupe déjà ce créneau à cette date (#221)
     */
    public function update(int $exceptionId, \DateTimeImmutable $occurrenceDate, ?string $reason): bool;

    /**
     * Supprime une demande en_attente. Retourne false si elle n'existe plus
     * ou a déjà été traitée.
     */
    public function delete(int $exceptionId): bool;
}
