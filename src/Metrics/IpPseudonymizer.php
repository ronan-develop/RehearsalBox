<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Empreinte d'une adresse IP pour les rapports : HMAC-SHA256 avec un secret du serveur (`metrics.secret`), tronqué à 16
 * caractères. Stable (un même visiteur donne la même empreinte : on peut repérer une rafale), non réversible sans le secret
 * (un simple hachage d'IPv4 se retrouverait par force brute). SANS secret configuré, aucune empreinte : mieux vaut ne rien
 * conserver qu'une empreinte devinable.
 */
final class IpPseudonymizer
{
    public function __construct(private readonly string $secret)
    {
    }

    public function of(string $ip): ?string
    {
        if ($ip === '' || $this->secret === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $ip, $this->secret), 0, 16);
    }
}
