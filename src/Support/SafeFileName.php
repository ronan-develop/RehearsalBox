<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Nom d'un fichier reçu tel qu'il sera conservé et AFFICHÉ (documents de groupe, relevés de compte) : sans chemin (`/` ou `\` et tout ce qui
 * précède), sans caractère de contrôle (retour à la ligne : injection d'en-tête) ni de format (inversion du sens d'écriture U+202E : « fdp.exe »
 * affiché à l'envers), borné en longueur sans couper un caractère ni l'extension. Il ne sert JAMAIS à construire un chemin sur le disque.
 */
final class SafeFileName
{
    public static function clean(string $originalName, int $maxLength, string $fallback): string
    {
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $originalName);
        $name = trim((string) preg_replace('~^.*[\\\\/]~u', '', $name));
        if ($name === '' || trim($name, '.') === '') {
            return $fallback;
        }
        if (mb_strlen($name) <= $maxLength) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && mb_strlen($extension) <= 10 ? '.' . $extension : '';

        return mb_substr($name, 0, $maxLength - mb_strlen($suffix)) . $suffix;
    }
}
