<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\ConversationMessageRepositoryInterface;
use App\Service\Exception\ConversationRateLimitException;

/**
 * Limite d'écriture de la messagerie, partagée par l'envoi, le renommage et l'édition d'un message (#200) : au plus
 * MAX_PER_HOUR messages, lignes ou modifications par personne et par heure (déjà stockés : aucun compteur à part).
 */
final class ConversationRateLimit
{
    public const MAX_PER_HOUR = 30;

    public function __construct(private readonly ConversationMessageRepositoryInterface $messages)
    {
    }

    /** @throws ConversationRateLimitException */
    public function assertWithin(int $userId, \DateTimeImmutable $now): void
    {
        $since = $now->modify('-1 hour');
        if ($this->messages->countMessagesBySince($userId, $since) + $this->messages->countEditsBySince($userId, $since) >= self::MAX_PER_HOUR) {
            throw new ConversationRateLimitException('Trop de messages envoyés : réessayez dans un moment.');
        }
    }
}
