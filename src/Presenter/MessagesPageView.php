<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ConversationAlert;
use App\Entity\ConversationMessage;
use App\Entity\ConversationThread;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Service\ConversationService;
use App\Service\ConversationTrashService;
use Symfony\Component\Clock\ClockInterface;

/**
 * Données des gabarits de la messagerie (#183) : colonne de gauche (liste) et fil. Construit à partir des services ; les
 * gabarits PHP ne font que dessiner. Le même constructeur sert aux pages et aux fragments HTML de mise à jour.
 */
final class MessagesPageView
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationListView $listView,
        private readonly ConversationTimeline $timeline,
        private readonly ConversationFormatter $formatter,
        private readonly ClockInterface $clock,
        private readonly ConversationTrashService $trash,
    ) {
    }

    /**
     * Colonne de gauche. Sans $box imposé : la liste active, ou les archives si la conversation ouverte y est.
     *
     * @return array{items: list<array<string, mixed>>, box: string, archivedUnread: int, alerts: list<array{id: int, text: string, url: ?string}>, trashCount: int}
     */
    public function sidebar(int $userId, ?int $activeId, ?string $box = null): array
    {
        $now = $this->clock->now();
        $chosen = $box ?? ConversationRepositoryInterface::BOX_ACTIVE;
        $summaries = $this->conversations->listFor($userId, $chosen);

        if ($box === null && $activeId !== null && !$this->contains($summaries, $activeId)) {
            $archived = $this->conversations->listFor($userId, ConversationRepositoryInterface::BOX_ARCHIVED);
            if ($this->contains($archived, $activeId)) {
                $chosen = ConversationRepositoryInterface::BOX_ARCHIVED;
                $summaries = $archived;
            }
        }

        return [
            'items' => $this->listView->items($summaries, $userId, $now, $activeId),
            'box' => $chosen,
            'archivedUnread' => $this->conversations->unreadCount($userId, ConversationRepositoryInterface::BOX_ARCHIVED),
            'alerts' => array_map($this->alert(...), $this->trash->alertsFor($userId)),
            'trashCount' => $this->trash->trashCount($userId),
        ];
    }

    /**
     * Page « Corbeille » : conversations que la personne a supprimées et qu'elle peut encore restaurer.
     *
     * @return list<array{id: int, title: string, deletedOn: string, daysLeft: int}>
     */
    public function trash(int $userId): array
    {
        return $this->listView->trash($this->trash->trash($userId), $this->clock->now(), ConversationTrashService::TRASH_RETENTION);
    }

    /**
     * @return array{id: int, title: ?string, displayTitle: string, label: string, rows: list<array<string, mixed>>, status: string, typing: bool, lastId: int, canDelete: bool}
     */
    public function thread(ConversationThread $thread, int $userId): array
    {
        $authorGroups = [];
        foreach ($thread->messages() as $message) {
            $authorGroups[$message->authorId()] = $thread->authorGroup($message->authorId());
        }
        $typing = $this->formatter->typingText($thread->typing());

        return [
            'id' => $thread->conversation()->id(),
            'title' => $thread->conversation()->title(),
            'displayTitle' => $thread->displayTitle(),
            'label' => $thread->label(),
            'rows' => $this->timeline->rows($thread->messages(), $userId, $this->clock->now(), $authorGroups, $thread->firstUnreadId(), $thread->previous()),
            'status' => $typing !== '' ? $typing : $this->formatter->seenText($thread->seen()),
            'typing' => $typing !== '',
            'lastId' => $this->lastId($thread->messages()),
            'canDelete' => $thread->conversation()->createdBy() === $userId,
        ];
    }

    /** @return array{id: int, text: string, url: ?string} */
    private function alert(ConversationAlert $alert): array
    {
        $restored = $alert->kind() === ConversationAlert::RESTORED;

        return [
            'id' => $alert->id(),
            'text' => $alert->label() . ' : ' . ($restored ? 'la conversation a été restaurée.' : "la conversation a été supprimée par la personne qui l'avait ouverte."),
            'url' => $restored && $alert->conversationId() !== null ? '/messages/' . $alert->conversationId() : null,
        ];
    }

    /** @param list<ConversationMessage> $messages */
    private function lastId(array $messages): int
    {
        return $messages === [] ? 0 : $messages[array_key_last($messages)]->id();
    }

    /** @param list<\App\Entity\ConversationSummary> $summaries */
    private function contains(array $summaries, int $conversationId): bool
    {
        foreach ($summaries as $summary) {
            if ($summary->conversation()->id() === $conversationId) {
                return true;
            }
        }

        return false;
    }
}
