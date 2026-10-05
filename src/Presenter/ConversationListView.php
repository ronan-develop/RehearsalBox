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
     * @return list<array{id: int, url: string, title: string, date: string, preview: string, unread: bool, mentioned: bool, canDelete: bool, active: bool}>
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
                'mentioned' => $summary->isMentioned(),
                'canDelete' => $summary->conversation()->createdBy() === $viewerId,
                'active' => $summary->conversation()->id() === $activeId,
            ];
        }, $summaries);
    }

    /**
     * Corbeille (#190) : conversations mises à la corbeille, avec le délai avant leur suppression définitive.
     *
     * @param list<ConversationSummary> $summaries
     *
     * @return list<array{id: int, title: string, deletedOn: string, daysLeft: int}>
     */
    public function trash(array $summaries, \DateTimeImmutable $now, string $retention): array
    {
        return array_map(function (ConversationSummary $summary) use ($now, $retention): array {
            $deletedAt = $summary->conversation()->deletedAt() ?? $now;
            $expires = $deletedAt->modify(str_replace('-', '+', $retention));

            return [
                'id' => $summary->conversation()->id(),
                'title' => $summary->displayTitle(),
                'deletedOn' => $deletedAt->setTimezone($this->formatter->timezone())->format('d/m/Y'),
                'daysLeft' => max(0, (int) ceil(($expires->getTimestamp() - $now->getTimestamp()) / 86400)),
            ];
        }, $summaries);
    }
}
