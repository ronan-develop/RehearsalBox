<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ConversationSummary;

/**
 * Lignes de la liste des conversations (#183), rendues par le serveur : lien, titre, date, aperçu, point « non lu »,
 * conversation ouverte. Utilisées par les pages et par le rafraîchissement de la liste (fragment HTML).
 */
final class ConversationListView
{
    public function __construct(private readonly ConversationFormatter $formatter)
    {
    }

    /**
     * @param list<ConversationSummary> $summaries
     *
     * @return list<array{id: int, url: string, title: string, date: string, preview: string, unread: bool, active: bool}>
     */
    public function items(array $summaries, int $viewerId, \DateTimeImmutable $now, ?int $activeId): array
    {
        return array_map(function (ConversationSummary $summary) use ($viewerId, $now, $activeId): array {
            $last = $summary->lastMessage();

            return [
                'id' => $summary->conversation()->id(),
                'url' => '/messages/' . $summary->conversation()->id(),
                'title' => $summary->displayTitle(),
                'date' => $this->formatter->listDate($last->createdAt(), $now),
                'preview' => $this->formatter->preview($last, $last->authorId() === $viewerId),
                'unread' => $summary->isUnread(),
                'active' => $summary->conversation()->id() === $activeId,
            ];
        }, $summaries);
    }
}
