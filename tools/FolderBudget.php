<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Budget de classes par dossier (#287) : un dossier de src/ ne peut pas accumuler les fichiers PHP sans limite (les sous-dossiers
 * comptent chacun à part). Même cliquet que SizeBudget : un dossier déjà trop plein est toléré à son effectif enregistré, ne peut
 * plus grossir, et son exception doit disparaître dès qu'il repasse sous le plafond (ou qu'il n'existe plus).
 */
final class FolderBudget
{
    /** @param array<string, int> $exceptions dossier relatif à src/ => effectif toléré */
    public function __construct(
        private readonly int $maxClasses,
        private readonly array $exceptions = [],
    ) {
    }

    /** @return list<string> un message par problème, vide si tout respecte le plafond */
    public function check(string $directory): array
    {
        $counts = $this->countsByFolder(rtrim($directory, '/'));
        $problems = [];

        foreach ($counts as $folder => $count) {
            $label = $folder === '' ? '(racine)' : $folder;
            $tolerated = $this->exceptions[$folder] ?? null;

            if ($tolerated === null) {
                if ($count > $this->maxClasses) {
                    $problems[] = "{$label} : {$count} classes (plafond {$this->maxClasses}).";
                }
                continue;
            }
            if ($count <= $this->maxClasses) {
                $problems[] = "{$label} : exception obsolète, le dossier respecte maintenant le plafond (à retirer de la liste).";
            } elseif ($count > $tolerated) {
                $problems[] = "{$label} : ne doit plus grossir ({$count} classes, toléré {$tolerated}).";
            }
        }

        foreach (array_keys($this->exceptions) as $folder) {
            if (!isset($counts[$folder])) {
                $problems[] = "{$folder} : exception obsolète, le dossier n'existe plus (à retirer de la liste).";
            }
        }

        return $problems;
    }

    /** @return array<string, int> dossier relatif (« » pour la racine) => nombre de fichiers PHP directement dedans */
    private function countsByFolder(string $root): array
    {
        $counts = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                $counts[substr($entry->getPathname(), strlen($root) + 1)] ??= 0;
            } elseif ($entry->getExtension() === 'php') {
                $folder = substr($entry->getPath(), strlen($root) + 1); // « » pour la racine
                $counts[$folder] = ($counts[$folder] ?? 0) + 1;
            }
        }
        $counts[''] ??= 0;
        ksort($counts);

        return $counts;
    }
}
