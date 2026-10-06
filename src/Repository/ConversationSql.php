<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\ConversationRepositoryInterface;

/** Fragments SQL de la messagerie partagés par plusieurs dépôts (alias attendus : `c` conversation, `lm` dernier message, `s` état de lecture). */
final class ConversationSql
{
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    // Dernier message du fil : sert à l'aperçu, au tri et à l'archivage dérivé (inactivité).
    public const LAST_MESSAGE_JOIN = 'JOIN conversation_messages lm ON lm.id = (
        SELECT MAX(x.id) FROM conversation_messages x WHERE x.conversation_id = c.id
    )';

    // Sourdine (#210) : une conversation en sourdine n'a ni « non lu » ni mention marquée, donc n'entre dans aucun compteur.
    private const NOT_MUTED = 'COALESCE(s.muted, 0) = 0';

    // Mention non lue : un message qui désigne la personne, plus récent que sa dernière lecture.
    public const MENTIONED_USER = self::NOT_MUTED . ' AND EXISTS (
        SELECT 1 FROM message_mentions mm JOIN conversation_messages mmsg ON mmsg.id = mm.message_id
        WHERE mmsg.conversation_id = c.id AND mm.user_id = :mention_user
          AND (s.last_read_at IS NULL OR mmsg.created_at > s.last_read_at)
    )';

    public const UNREAD_FOR_USER = self::NOT_MUTED . ' AND EXISTS (
        SELECT 1 FROM conversation_messages um
        WHERE um.conversation_id = c.id AND um.author_id <> :unread_user
          AND (s.last_read_at IS NULL OR um.created_at > s.last_read_at)
    )';

    /**
     * Condition SQL : la personne désignée par $userExpr participe à c, c'est-à-dire membre de l'un des deux groupes ou
     * invitée à cette conversation (comptée une seule fois). $userExpr n'apparaît qu'une fois (paramètre nommé).
     */
    public static function visibleTo(string $userExpr): string
    {
        return $userExpr . ' IN (
            SELECT gu.user_id FROM group_user gu WHERE gu.group_id IN (c.initiator_group_id, c.target_group_id)
            UNION
            SELECT cg.user_id FROM conversation_guests cg WHERE cg.conversation_id = c.id
        )';
    }

    /** Active = dernier message à partir de la limite d'inactivité (:cutoff incluse) ; archivée = antérieur. */
    public static function boxCondition(string $box): string
    {
        return $box === ConversationRepositoryInterface::BOX_ARCHIVED ? 'lm.created_at < :cutoff' : 'lm.created_at >= :cutoff';
    }
}
