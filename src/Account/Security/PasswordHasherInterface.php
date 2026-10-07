<?php

declare(strict_types=1);

namespace App\Account\Security;

interface PasswordHasherInterface
{
    public function hash(string $plainPassword): string;

    public function verify(string $plainPassword, string $hash): bool;

    /**
     * Dépense le temps d'une vérification sans rien vérifier : à appeler quand aucun hachage n'est disponible
     * (compte inconnu, inactif, verrouillé), pour que le temps de réponse ne révèle pas l'existence du compte.
     */
    public function simulateVerification(string $plainPassword): void;
}
