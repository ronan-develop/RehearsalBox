<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Repository\ThrottleEventRepositoryInterface;

/**
 * Limite d'évènements PAR ADRESSE sur une route sensible (échecs de connexion #218, demandes de mot de passe oublié #219) :
 * `$maxEvents` évènements dans `$window`, puis l'adresse est refusée un moment. Elle ne verrouille jamais un compte et ne
 * dépend d'aucun compte (rien à énumérer). Chaque limite a son étiquette : deux limites ne se mélangent pas. L'adresse n'est
 * jamais stockée en clair (empreinte propre à l'application) et les évènements de plus de 24 h sont purgés.
 */
final class IpThrottle
{
    private const RETENTION = '-24 hours';
    private const HASH_LABEL = 'rehearsalbox-throttle';

    /** @param string $window durée relative négative, ex. « -15 minutes » */
    public function __construct(
        private readonly ThrottleEventRepositoryInterface $events,
        private readonly string $label,
        private readonly int $maxEvents,
        private readonly string $window,
    ) {
    }

    public function isBlocked(string $ip, \DateTimeImmutable $now): bool
    {
        // Adresse inconnue : on ne bloque personne (sinon un serveur mal configuré bloquerait tout le monde).
        if ($ip === '') {
            return false;
        }

        return $this->events->countSince($this->hashOf($ip), $now->modify($this->window)) >= $this->maxEvents;
    }

    public function record(string $ip, \DateTimeImmutable $now): void
    {
        if ($ip === '') {
            return;
        }

        $this->events->purgeBefore($now->modify(self::RETENTION));
        $this->events->record($this->hashOf($ip), $now);
    }

    /** Durée à indiquer au client (en-tête Retry-After) : la fenêtre entière, par prudence. */
    public function retryAfterSeconds(): int
    {
        $now = new \DateTimeImmutable('2000-01-01 00:00:00');

        return $now->getTimestamp() - $now->modify($this->window)->getTimestamp();
    }

    private function hashOf(string $ip): string
    {
        return hash('sha256', self::HASH_LABEL . '|' . $this->label . '|' . $ip);
    }
}
