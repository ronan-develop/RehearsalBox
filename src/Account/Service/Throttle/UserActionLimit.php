<?php

declare(strict_types=1);

namespace App\Account\Service\Throttle;

use App\Security\Exception\RateLimitedException;
use Symfony\Component\Clock\ClockInterface;

/**
 * Limite d'UNE action par PERSONNE connectée (#428) : recherche de membres, envoi de documents… Même mécanique que les limites de
 * connexion (SubjectThrottle : comptage de lignes, aucune dépendance à un cache), avec la personne comme sujet. Une action refusée
 * n'est pas comptée : le blocage ne se prolonge pas en insistant.
 */
final class UserActionLimit
{
    public function __construct(
        private readonly SubjectThrottle $throttle,
        private readonly ClockInterface $clock,
        private readonly string $message,
    ) {
    }

    /** @throws RateLimitedException la limite est atteinte */
    public function hit(int $userId): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $subject = 'user:' . $userId;

        if ($this->throttle->isBlocked($subject, $now)) {
            throw new RateLimitedException($this->message, $this->throttle->remainingSeconds($subject, $now));
        }
        $this->throttle->record($subject, $now);
    }
}
