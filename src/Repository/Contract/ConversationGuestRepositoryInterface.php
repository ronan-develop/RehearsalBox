<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/** Invités d'une conversation : membres du site extérieurs aux deux groupes, qui n'accèdent qu'à cette conversation. */
interface ConversationGuestRepositoryInterface
{
    /** Ajoute l'invité ; sans effet s'il l'est déjà. Renvoie true si la ligne vient d'être créée. */
    public function add(int $conversationId, int $userId, int $addedBy, \DateTimeImmutable $now): bool;

    public function remove(int $conversationId, int $userId): void;

    public function isGuest(int $conversationId, int $userId): bool;

    /** Personne qui a ajouté l'invité, null s'il n'est pas invité ou si son compte a disparu. */
    public function addedBy(int $conversationId, int $userId): ?int;
}
