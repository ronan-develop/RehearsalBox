<?php

declare(strict_types=1);

namespace App\Messaging\Repository\Mention;

interface ConversationMentionRepositoryInterface
{
    /**
     * Enregistre les personnes mentionnées par un message ; remplace les mentions précédentes du même message.
     *
     * @param array<int, string> $labelsByUserId identifiant de la personne => texte « @Nom » inséré dans le message
     */
    public function record(int $messageId, array $labelsByUserId): void;

    /**
     * @param list<int> $messageIds
     *
     * @return array<int, array<int, string>> identifiant du message => (identifiant de la personne => « @Nom ») ; les messages sans mention sont absents
     */
    public function forMessages(array $messageIds): array;
}
