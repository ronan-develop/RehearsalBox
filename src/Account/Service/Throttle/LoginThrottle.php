<?php

declare(strict_types=1);

namespace App\Account\Service\Throttle;

/**
 * Blocage de la connexion (#218, #236), PAR ADRESSE IP et PAR IDENTIFIANT SAISI. Aucun des deux ne regarde un compte : un compte réel
 * et une adresse inventée ont exactement le même compteur, donc le même message et la même durée annoncée (rien à énumérer). L'identifiant
 * est normalisé (espaces et casse) pour qu'on ne contourne pas la limite en changeant de casse ; il n'est jamais stocké en clair
 * (empreinte propre à l'application, voir SubjectThrottle). Le verrou propre au compte (AuthService) reste en place, en plus.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly SubjectThrottle $byAddress,
        private readonly SubjectThrottle $byIdentifier,
    ) {
    }

    /** Secondes à attendre avant de pouvoir réessayer (0 : pas de blocage) : la plus longue des deux limites. */
    public function remainingSeconds(string $ip, string $email, \DateTimeImmutable $now): int
    {
        return max(
            $this->byAddress->remainingSeconds($ip, $now),
            $this->byIdentifier->remainingSeconds(self::identifier($email), $now),
        );
    }

    public function recordFailure(string $ip, string $email, \DateTimeImmutable $now): void
    {
        $this->byAddress->record($ip, $now);
        $this->byIdentifier->record(self::identifier($email), $now);
    }

    /** Connexion réussie ou déblocage par un administrateur : le compteur de cet identifiant repart de zéro (celui de l'adresse reste). */
    public function forgetIdentifier(string $email): void
    {
        $this->byIdentifier->forget(self::identifier($email));
    }

    private static function identifier(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
