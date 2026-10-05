<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\LoginFailureRepositoryInterface;

/**
 * Limite des échecs de connexion PAR ADRESSE (#218), en plus du verrou par compte. Elle ne verrouille jamais un compte :
 * une adresse qui multiplie les échecs est simplement refusée un moment, sans rien révéler sur les comptes.
 * L'adresse n'est jamais stockée en clair (empreinte), et les échecs de plus de 24 h sont purgés.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 20;
    public const WINDOW = '-15 minutes';
    public const RETRY_AFTER_SECONDS = 900;
    private const RETENTION = '-24 hours';
    private const HASH_LABEL = 'rehearsalbox-login-throttle';

    public function __construct(private readonly LoginFailureRepositoryInterface $failures)
    {
    }

    public function isBlocked(string $ip, \DateTimeImmutable $now): bool
    {
        // Adresse inconnue : on ne bloque personne (sinon un serveur mal configuré bloquerait tout le monde).
        if ($ip === '') {
            return false;
        }

        return $this->failures->countSince($this->hashOf($ip), $now->modify(self::WINDOW)) >= self::MAX_FAILURES;
    }

    public function recordFailure(string $ip, \DateTimeImmutable $now): void
    {
        if ($ip === '') {
            return;
        }

        $this->failures->purgeBefore($now->modify(self::RETENTION));
        $this->failures->record($this->hashOf($ip), $now);
    }

    private function hashOf(string $ip): string
    {
        return hash('sha256', self::HASH_LABEL . '|' . $ip);
    }
}
