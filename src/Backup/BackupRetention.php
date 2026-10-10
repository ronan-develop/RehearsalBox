<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Rotation des sauvegardes (#167) : parmi les fichiers d'UN type (`db-<horodatage>.sql.gz` quotidien, `pre-<release>.sql.gz` d'avant
 * déploiement), garde les `$keep` plus récents et désigne les autres. Ne propose JAMAIS autre chose : un fichier inconnu, un dump
 * partiel, un autre type de sauvegarde ne correspondent pas au motif et ne sont jamais supprimés. L'ordre vient de l'horodatage
 * de 14 chiffres du nom, pas de la date de modification du fichier.
 */
final class BackupRetention
{
    private readonly string $pattern;

    /** @throws \InvalidArgumentException préfixe invalide, ou moins d'une sauvegarde à garder */
    public function __construct(string $prefix, private readonly int $keep)
    {
        if (preg_match('/^[a-z]{1,12}$/', $prefix) !== 1) {
            throw new \InvalidArgumentException('Préfixe de sauvegarde invalide.');
        }
        if ($keep < 1) {
            throw new \InvalidArgumentException('Il faut garder au moins une sauvegarde.');
        }
        $this->pattern = '/^' . $prefix . '-(\d{14})[A-Za-z0-9._-]*\.sql\.gz$/';
    }

    /**
     * @param list<string> $files noms de fichiers (sans dossier) présents dans le dossier de sauvegarde
     *
     * @return list<string> à supprimer, du plus récent au plus ancien
     */
    public function expired(array $files): array
    {
        $dated = [];
        foreach ($files as $file) {
            if (preg_match($this->pattern, $file, $parts) === 1) {
                $dated[$file] = $parts[1];
            }
        }
        arsort($dated, SORT_STRING);

        return array_slice(array_keys($dated), $this->keep);
    }
}
