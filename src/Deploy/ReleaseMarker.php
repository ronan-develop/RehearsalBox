<?php

declare(strict_types=1);

namespace App\Deploy;

/**
 * Marqueur opaque de la release servie (en-tête X-Release) : empreinte courte
 * de l'identifiant de release, sans commit ni chemin. bin/deploy.sh calcule la
 * même empreinte (sha256sum) pour vérifier que la nouvelle release est servie.
 */
final class ReleaseMarker
{
    public static function fingerprint(string $release): string
    {
        return substr(hash('sha256', $release), 0, 12);
    }

    /** Fichier RELEASE écrit par le déploiement ; null hors production. */
    public static function fromFile(string $path): ?string
    {
        $release = is_file($path) ? trim((string) file_get_contents($path)) : '';

        return $release === '' ? null : self::fingerprint($release);
    }
}
