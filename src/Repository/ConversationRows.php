<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Entity\MessageQuote;

/** Lignes SQL de la messagerie → entités. Fonctions pures, partagées par les dépôts de la messagerie. */
final class ConversationRows
{
    /** @param array<string, mixed> $row ligne de liste ; `unread` absent (corbeille) = lu */
    public static function summary(array $row): ConversationSummary
    {
        return new ConversationSummary(
            self::conversation($row),
            (string) $row['initiator_name'],
            (string) $row['target_name'],
            self::message($row, (int) $row['id'], 'last_'),
            (bool) ($row['unread'] ?? false),
            (bool) ($row['mentioned'] ?? false),
            (bool) ($row['muted'] ?? false),
        );
    }

    /** @param array<string, mixed> $row */
    public static function conversation(array $row): Conversation
    {
        return new Conversation(
            (int) $row['id'],
            (int) $row['initiator_group_id'],
            (int) $row['target_group_id'],
            $row['title'] === null ? null : (string) $row['title'],
            new \DateTimeImmutable($row['created_at']),
            $row['created_by'] === null ? null : (int) $row['created_by'],
            $row['deleted_at'] === null ? null : new \DateTimeImmutable($row['deleted_at']),
        );
    }

    /**
     * @param array<string, mixed> $row    colonnes id, author_id, author_name, body, created_at, éventuellement préfixées
     * @param string               $prefix préfixe des colonnes du message dans la ligne (ex. « last_ »)
     */
    public static function message(array $row, int $conversationId, string $prefix = ''): ConversationMessage
    {
        return new ConversationMessage(
            (int) $row[$prefix . 'id'],
            $conversationId,
            (int) $row[$prefix . 'author_id'],
            (string) $row[$prefix . 'author_name'],
            (string) $row[$prefix . 'body'],
            new \DateTimeImmutable($row[$prefix . 'created_at']),
            (bool) $row[$prefix . 'is_system'],
            isset($row[$prefix . 'edited_at']) ? new \DateTimeImmutable($row[$prefix . 'edited_at']) : null,
            isset($row[$prefix . 'quote_id']) ? new MessageQuote((int) $row[$prefix . 'quote_id'], (string) $row[$prefix . 'quote_author'], (string) $row[$prefix . 'quote_body']) : null,
        );
    }
}
