<?php

declare(strict_types=1);

namespace App\Assembly\Service;

/**
 * Règles de champ communes à la saisie d'un compte rendu d'AG (#361) : texte borné, heure HH:MM, nombre de voix. Chaque refus
 * s'ajoute aux erreurs (clé pointée) sans en écraser une déjà posée.
 */
final class AssemblyFieldRules
{
    /** Caractère de contrôle interdit dans un nom. */
    public const CONTROL = '/[\x00-\x1F\x7F]/u';

    /** Caractère de contrôle interdit dans un texte long : le retour à la ligne et la tabulation restent permis. */
    public const LINES = '/[\x00-\x08\x0B-\x1F\x7F]/u';

    public const MAX_SHORT = 200;
    public const MAX_NAME = 120;
    public const MAX_LONG = 20000;

    /** @param array<string, string> $errors */
    public function fail(array &$errors, string $key, string $message): void
    {
        $errors[$key] ??= $message;
    }

    /**
     * Texte facultatif : null s'il est absent ou vide une fois rogné. `$forbidden` est l'expression des caractères interdits (null = aucun).
     *
     * @param array<string, string> $errors
     */
    public function text(mixed $value, string $key, int $max, ?string $forbidden, array &$errors): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $this->fail($errors, $key, 'Ce champ doit être un texte.');

            return null;
        }
        $clean = trim($value);
        if ($clean === '') {
            return null;
        }
        if (mb_strlen($clean) > $max) {
            $this->fail($errors, $key, 'Ce texte ne peut pas dépasser ' . $max . ' caractères.');

            return null;
        }
        if ($forbidden !== null && preg_match($forbidden, $clean) === 1) {
            $this->fail($errors, $key, 'Ce texte contient un caractère non autorisé.');

            return null;
        }

        return $clean;
    }

    /** @param array<string, string> $errors */
    public function time(mixed $value, string $key, array &$errors): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value) === 1) {
            return $value;
        }
        $this->fail($errors, $key, 'L\'heure doit être écrite HH:MM (par exemple 19:30).');

        return null;
    }

    /**
     * Nombre de voix : entier de 0 à 9999, accepté en entier ou en chaîne de chiffres. Absent = 0.
     *
     * @param array<string, string> $errors
     */
    public function vote(mixed $value, string $key, array &$errors): int
    {
        if ($value === null) {
            return 0;
        }
        if (is_int($value) && $value >= 0 && $value <= 9999) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,4}$/', $value) === 1) {
            return (int) $value;
        }
        $this->fail($errors, $key, 'Le nombre de voix doit être un entier de 0 à 9999.');

        return 0;
    }
}
