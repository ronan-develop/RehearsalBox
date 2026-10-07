<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Group\Entity\Group;
use App\Support\Initials;
use App\Support\SafeColor;

/**
 * Objets de lecture JSON de la messagerie (liste pour la pastille « non lu » du dashboard). Le fil et la liste affichés
 * sont rendus par le serveur (#183, MessagesPageView) : plus de JSON destiné à être dessiné par le navigateur.
 */
final class ConversationPresenter
{
    /** @return array<string, mixed> */
    public function summary(ConversationSummary $summary, int $viewerId): array
    {
        return [
            'id' => $summary->conversation()->id(),
            'title' => $summary->conversation()->title(),
            'displayTitle' => $summary->displayTitle(),
            'label' => $summary->label(),
            'unread' => $summary->isUnread(),
            'lastMessage' => $this->message($summary->lastMessage(), null, $viewerId),
        ];
    }

    /** @return array<string, mixed> */
    public function message(ConversationMessage $message, ?Group $authorGroup, int $viewerId): array
    {
        return [
            'id' => $message->id(),
            'authorName' => $message->authorName(),
            'initials' => Initials::from($message->authorName()),
            'groupName' => $authorGroup?->name(),
            'groupColor' => SafeColor::from($authorGroup?->colorHex()),
            'body' => $message->body(),
            'system' => $message->isSystem(),
            'createdAt' => $message->createdAt()->format(\DATE_ATOM),
            'mine' => $message->authorId() === $viewerId,
        ];
    }
}
