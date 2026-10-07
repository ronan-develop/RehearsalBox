<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\Conversation;
use App\Group\Entity\Group;

/** Prévient le groupe visé d'une nouvelle conversation (une seule fois, sans le contenu) ; un échec d'envoi ne remonte jamais. */
interface NewConversationNotifierInterface
{
    public function newConversation(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void;
}
