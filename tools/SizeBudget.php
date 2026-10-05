<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Budget de taille des classes : un garde-fou automatique contre les classes qui grossissent ticket après ticket.
 *
 * Une classe déjà trop grosse est tolérée à sa taille enregistrée (cliquet) : elle ne peut plus grossir,
 * et son exception doit disparaître dès qu'elle repasse sous le budget.
 */
final class SizeBudget
{
    /** @param array<string, array{lines: int, public: int}> $exceptions chemin relatif au dossier analysé => taille tolérée */
    public function __construct(
        private readonly int $maxLines,
        private readonly int $maxPublicMethods,
        private readonly array $exceptions = [],
    ) {
    }

    /** @return list<string> un message par problème, vide si tout respecte le budget */
    public function check(string $directory): array
    {
        $problems = [];
        $seen = [];

        foreach ($this->phpFiles($directory) as $relative => $path) {
            $seen[$relative] = true;
            $code = (string) file_get_contents($path);
            $lines = substr_count($code, "\n") + 1;
            $public = preg_match_all('/^\s*public\s+(?:static\s+)?function\s+(?!__construct\b)/m', $code);

            $overBudget = $lines > $this->maxLines || $public > $this->maxPublicMethods;
            $tolerated = $this->exceptions[$relative] ?? null;

            if ($tolerated === null) {
                $problems = [...$problems, ...$this->overBudget($relative, $lines, $public)];
                continue;
            }
            if (!$overBudget) {
                $problems[] = "{$relative} : exception obsolète, la classe respecte maintenant le budget (à retirer de la liste).";
                continue;
            }
            if ($lines > $tolerated['lines'] || $public > $tolerated['public']) {
                $problems[] = "{$relative} : ne doit plus grossir ({$lines} lignes et {$public} méthodes publiques, toléré {$tolerated['lines']} et {$tolerated['public']}).";
            }
        }

        foreach (array_keys($this->exceptions) as $relative) {
            if (!isset($seen[$relative])) {
                $problems[] = "{$relative} : exception obsolète, le fichier n'existe plus (à retirer de la liste).";
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private function overBudget(string $relative, int $lines, int $public): array
    {
        $problems = [];
        if ($lines > $this->maxLines) {
            $problems[] = "{$relative} : {$lines} lignes (budget {$this->maxLines}).";
        }
        if ($public > $this->maxPublicMethods) {
            $problems[] = "{$relative} : {$public} méthodes publiques (budget {$this->maxPublicMethods}).";
        }

        return $problems;
    }

    /** @return array<string, string> chemin relatif => chemin complet, triés */
    private function phpFiles(string $directory): array
    {
        $root = rtrim($directory, '/');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }
}
