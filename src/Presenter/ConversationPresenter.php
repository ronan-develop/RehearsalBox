<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Entity\ConversationThread;
use App\Entity\Group;
use App\Support\Initials;

/**
 * Objets de lecture de la messagerie : transforme les entités en tableaux prêts pour le client. Aucune règle métier,
 * aucun identifiant interne inutile (ni auteur, ni groupe) : réutilisable par les pages, l'API, un futur sérialiseur.
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
    public function thread(ConversationThread $thread, int $viewerId): array
    {
        $seen = $thread->seen();

        return [
            'id' => $thread->conversation()->id(),
            'title' => $thread->conversation()->title(),
            'displayTitle' => $thread->displayTitle(),
            'label' => $thread->label(),
            'messages' => array_map(
                fn (ConversationMessage $message): array => $this->message($message, $thread->authorGroup($message->authorId()), $viewerId),
                $thread->messages(),
            ),
            'typing' => $thread->typing(),
            'seen' => $seen === null ? null : ['messageId' => $seen->messageId(), 'names' => $seen->names(), 'total' => $seen->total()],
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
            'groupColor' => $authorGroup?->colorHex(),
            'body' => $message->body(),
            'system' => $message->isSystem(),
            'createdAt' => $message->createdAt()->format(\DATE_ATOM),
            'mine' => $message->authorId() === $viewerId,
        ];
    }
}
