<?php

declare(strict_types=1);

namespace App\Operations\Production;

use App\Operations\BoundedFile;

/**
 * Les releases présentes sur le serveur (#375), vues comme bin/rollback.sh les voit : noms `AAAAMMJJHHMMSS-<commit>` triés, la cible d'un
 * retour arrière est celle juste AVANT la release active. Les liens symboliques, fichiers et noms hors motif sont ignorés. La date d'une
 * release est celle de son fichier RELEASE (horloge du serveur). Dossier absent : liste vide.
 */
final class ReleaseInventory
{
    private const PATTERN = '/^(\d{14})-([0-9a-f]{7,40})$/';

    public function __construct(
        private readonly ?string $releasesDir,
        private readonly string $currentRelease,
        private readonly \DateTimeZone $zone,
    ) {
    }

    /** L'identifiant de la release servie, lu dans le fichier RELEASE écrit par le déploiement ; vide si absent ou hors motif. */
    public static function markerIn(string $releaseFile): string
    {
        $marker = trim((string) BoundedFile::tail($releaseFile, 256));

        return preg_match(self::PATTERN, $marker) === 1 ? $marker : '';
    }

    /** @return list<array{id: string, commit: string, at: ?\DateTimeImmutable, current: bool, rollbackTarget: bool}> la plus récente d'abord */
    public function releases(): array
    {
        $ids = $this->ids();
        $position = array_search($this->currentRelease, $ids, true);
        $target = $position === false || $position === 0 ? null : $ids[$position - 1];

        $releases = [];
        foreach (array_reverse($ids) as $id) {
            preg_match(self::PATTERN, $id, $parts);
            $releases[] = [
                'id' => $id,
                'commit' => $parts[2],
                'at' => BoundedFile::mtime($this->releasesDir . '/' . $id . '/RELEASE', $this->zone),
                'current' => $id === $this->currentRelease,
                'rollbackTarget' => $id === $target,
            ];
        }

        return $releases;
    }

    /** @return list<string> triés par ordre croissant, comme `sort` dans rollback.sh */
    private function ids(): array
    {
        if ($this->releasesDir === null || !is_dir($this->releasesDir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($this->releasesDir) ?: [] as $name) {
            $path = $this->releasesDir . '/' . $name;
            if (preg_match(self::PATTERN, $name) === 1 && is_dir($path) && !is_link($path)) {
                $ids[] = $name;
            }
        }
        sort($ids, SORT_STRING);

        return $ids;
    }
}
