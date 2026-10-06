<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Règle des mots de passe (#223) : 10 caractères au moins (comptés en CARACTÈRES, une lettre accentuée vaut un), 72 octets
 * au plus (au-delà bcrypt tronquerait en silence : mieux vaut refuser que d'accepter un mot de passe dont la fin ne compte
 * pas), et pas un mot de passe parmi les plus courants. Elle ne s'applique qu'au moment de CHOISIR un mot de passe : les
 * comptes existants se connectent toujours avec l'ancien, jusqu'à leur prochain changement. Personne, administrateur
 * compris, ne connaît ni ne fixe jamais le mot de passe d'un autre.
 */
final class PasswordPolicy
{
    private const MIN_LENGTH = 10;
    private const MAX_BYTES = 72;

    /**
     * Mots de passe les plus courants (minuscules, sans accent), tous d'au moins 10 caractères puisque la longueur est déjà
     * exigée : une liste courte et lisible plutôt qu'un dictionnaire, pour écarter ce qu'un attaquant essaie en premier.
     */
    private const COMMON = [
        'password123', 'password1234', 'passw0rd123', 'password12345', 'motdepasse1', 'motdepasse123', 'motdepasse12',
        'azertyuiop', 'azerty1234', 'azerty12345', 'azertyuiop1', 'qwertyuiop', 'qwerty1234', 'qwerty12345', 'qwertyuiopasdf',
        '1234567890', '12345678910', '123456789012', '0123456789', '1q2w3e4r5t', '1qaz2wsx3edc', 'a1b2c3d4e5',
        'iloveyou123', 'welcome123', 'bienvenue1', 'bienvenue123', 'changeme123', 'changemoi123', 'letmein1234', 'administrateur',
        'rehearsalbox', 'rehearsalbox1', 'repetition1', 'repetition123', 'guitar1234', 'metallica1', 'metallica123',
    ];

    /** Message d'erreur si le mot de passe ne respecte pas la règle, null sinon. */
    public function violation(string $plainPassword): ?string
    {
        if (mb_strlen($plainPassword) < self::MIN_LENGTH) {
            return 'Le mot de passe doit faire au moins ' . self::MIN_LENGTH . ' caractères.';
        }
        if (strlen($plainPassword) > self::MAX_BYTES) {
            return 'Le mot de passe ne doit pas dépasser ' . self::MAX_BYTES . ' octets (environ ' . self::MAX_BYTES . ' caractères sans accent).';
        }
        if ($this->isTrivial($plainPassword)) {
            return 'Ce mot de passe est trop courant : choisissez-en un moins prévisible.';
        }

        return null;
    }

    private function isTrivial(string $plainPassword): bool
    {
        $normalized = mb_strtolower($plainPassword);

        return in_array($normalized, self::COMMON, true) || count(array_unique(mb_str_split($normalized))) === 1;
    }
}
