<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Participation;

use App\Messaging\Entity\ConversationAlert;

interface ConversationAlertRepositoryInterface
{
    /** Un avis pour chaque membre de l'un des deux groupes de la conversation, sauf $exceptUserId (l'auteur de l'action). */
    public function notifyParticipants(int $conversationId, int $exceptUserId, string $kind, \DateTimeImmutable $now): void;

    /** @return list<ConversationAlert> avis non fermés créés à partir de $since, le plus récent d'abord */
    public function findActiveFor(int $userId, \DateTimeImmutable $since): array;

    public function countActiveFor(int $userId, \DateTimeImmutable $since): int;

    /** Ferme l'avis s'il appartient bien à cette personne (sinon sans effet). */
    public function dismiss(int $alertId, int $userId, \DateTimeImmutable $now): void;
}
