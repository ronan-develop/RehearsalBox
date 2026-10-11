<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Durée de vie d'une session ouverte (#425) : une limite d'INACTIVITÉ (sans visite) et une limite ABSOLUE (depuis la connexion,
 * même si la personne revient chaque jour). Une session volée ne reste donc pas valable indéfiniment. Fonction pure : le temps
 * est passé en paramètre (horloge injectée par l'appelant).
 */
final class SessionPolicy
{
    public const IDLE_SECONDS = 7 * 86400;

    public const ABSOLUTE_SECONDS = 30 * 86400;

    public static function isExpired(int $startedAt, int $lastSeenAt, int $now): bool
    {
        return $now - $lastSeenAt > self::IDLE_SECONDS || $now - $startedAt > self::ABSOLUTE_SECONDS;
    }
}
