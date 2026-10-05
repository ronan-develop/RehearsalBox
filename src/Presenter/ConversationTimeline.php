<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\ConversationMessage;
use App\Entity\Group;
use App\Support\Initials;
use App\Support\SafeColor;

/**
 * Transforme des messages en lignes d'affichage (#183) : séparateurs de jour, séparateur « Messages non lus », lignes
 * système, bulles avec indicateur de série (l'auteur n'est affiché qu'au début d'une série). Les gabarits PHP dessinent
 * ces lignes : le même code sert au premier affichage de la page et aux mises à jour (fragments HTML).
 *
 * Types de lignes :
 *  - ['type' => 'day', 'label']
 *  - ['type' => 'unread']
 *  - ['type' => 'system', 'text']
 *  - ['type' => 'message', 'id', 'mine', 'startsRun', 'author', 'initials', 'groupName', 'color', 'body', 'time']
 */
final class ConversationTimeline
{
    public function __construct(private readonly ConversationFormatter $formatter)
    {
    }

    /**
     * @param list<ConversationMessage> $messages
     * @param array<int, Group|null>    $authorGroups groupe de chaque auteur (null : ambigu)
     * @param ConversationMessage|null  $previous     message qui précède $messages (lecture incrémentale) : continuité du jour et de la série
     *
     * @return list<array<string, mixed>>
     */
    public function rows(
        array $messages,
        int $viewerId,
        \DateTimeImmutable $now,
        array $authorGroups = [],
        ?int $firstUnreadId = null,
        ?ConversationMessage $previous = null,
    ): array {
        $rows = [];
        $previousAuthor = ($previous === null || $previous->isSystem()) ? null : $previous->authorName();
        $previousDayKey = $previous === null ? null : $this->dayKey($previous);

        foreach ($messages as $message) {
            $dayKey = $this->dayKey($message);
            if ($dayKey !== $previousDayKey) {
                $rows[] = ['type' => 'day', 'label' => $this->formatter->dayLabel($message->createdAt(), $now)];
                $previousDayKey = $dayKey;
                $previousAuthor = null;
            }
            if ($message->id() === $firstUnreadId) {
                $rows[] = ['type' => 'unread'];
                $previousAuthor = null;
            }
            $mine = $message->authorId() === $viewerId;
            if ($message->isSystem()) {
                $rows[] = ['type' => 'system', 'text' => $this->formatter->systemLine($message, $mine)];
                $previousAuthor = null;
                continue;
            }

            $group = $authorGroups[$message->authorId()] ?? null;
            $rows[] = [
                'type' => 'message',
                'id' => $message->id(),
                'mine' => $mine,
                'startsRun' => $previousAuthor !== $message->authorName() || $mine,
                'author' => $message->authorName(),
                'initials' => Initials::from($message->authorName()),
                'groupName' => $group?->name(),
                'color' => SafeColor::from($group?->colorHex()),
                'body' => $message->body(),
                'time' => $this->formatter->time($message->createdAt()),
            ];
            $previousAuthor = $message->authorName();
        }

        return $rows;
    }

    private function dayKey(ConversationMessage $message): string
    {
        return $message->createdAt()->setTimezone($this->formatter->timezone())->format('Y-m-d');
    }
}
