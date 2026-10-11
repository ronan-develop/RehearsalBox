<?php

declare(strict_types=1);

namespace App\Account\Repository;

/** Lecture seule de ce que l'application garde d'UNE personne (#431) : export de ses données, jamais celles d'une autre. */
interface AccountDataRepositoryInterface
{
    /** @return list<array{groupId: int, group: string, role: string}> */
    public function memberships(int $userId): array;

    /**
     * Messages écrits par la personne (texte déchiffré), hors lignes système.
     *
     * @return list<array{id: int, conversationId: int, sentAt: string, editedAt: ?string, text: string}>
     */
    public function messages(int $userId): array;

    /**
     * Réservations libres demandées par la personne.
     *
     * @return list<array{group: string, date: string, start: string, end: string, status: string, reason: ?string, createdAt: string}>
     */
    public function bookings(int $userId): array;

    /**
     * Demandes d'échange de créneau faites par la personne.
     *
     * @return list<array{group: string, date: string, status: string, reason: ?string, createdAt: string}>
     */
    public function slotRequests(int $userId): array;
}
