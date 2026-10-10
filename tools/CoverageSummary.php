<?php

declare(strict_types=1);

namespace App\Tools;

/**
 * Résumé de la couverture de tests (#129), lu dans le rapport Clover de PHPUnit : par dossier `Domaine/Couche`, par couche
 * (Service, Controller, Repository…) et au total, en instructions couvertes. Sert à repérer les trous, sans seuil bloquant.
 * Les fichiers hors de `src/` sont ignorés.
 */
final class CoverageSummary
{
    /**
     * @return array{total: array{covered: int, statements: int}, folders: array<string, array{covered: int, statements: int}>, layers: array<string, array{covered: int, statements: int}>}
     *
     * @throws \InvalidArgumentException rapport illisible
     */
    public function fromClover(string $cloverXml, string $srcDir): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($cloverXml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \InvalidArgumentException('Rapport de couverture illisible (Clover attendu).');
        }

        $prefix = rtrim($srcDir, '/') . '/';
        $total = ['covered' => 0, 'statements' => 0];
        $folders = [];
        $layers = [];
        foreach ($xml->xpath('//file') ?: [] as $file) {
            $path = (string) $file['name'];
            if (!str_starts_with($path, $prefix) || !isset($file->metrics)) {
                continue;
            }
            $statements = (int) $file->metrics['statements'];
            $covered = (int) $file->metrics['coveredstatements'];
            $segments = explode('/', substr($path, strlen($prefix)));
            array_pop($segments); // le nom du fichier

            $this->add($total, $statements, $covered);
            $this->add($folders[$this->folderKey($segments)], $statements, $covered);
            if (count($segments) >= 2) {
                $this->add($layers[$segments[1]], $statements, $covered);
            }
        }

        return ['total' => $total, 'folders' => $folders, 'layers' => $layers];
    }

    /** @param array{total: array{covered: int, statements: int}, folders: array<string, array{covered: int, statements: int}>, layers: array<string, array{covered: int, statements: int}>} $summary */
    public function toMarkdown(array $summary): string
    {
        $lines = [
            sprintf('**Total : %s** (%d / %d)', self::percent($summary['total']), $summary['total']['covered'], $summary['total']['statements']),
            '',
            ...$this->table('Dossier', $summary['folders']),
            '',
            ...$this->table('Couche', $summary['layers']),
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, array{covered: int, statements: int}> $rows
     *
     * @return list<string> le moins couvert d'abord, sans les dossiers sans instruction
     */
    private function table(string $title, array $rows): array
    {
        $rows = array_filter($rows, static fn (array $row): bool => $row['statements'] > 0); // rien à couvrir (exceptions, interfaces)
        uasort($rows, static fn (array $a, array $b): int => self::ratio($a) <=> self::ratio($b));
        $lines = ["| {$title} | Couverture | Instructions |", '|---|---|---|'];
        foreach ($rows as $name => $row) {
            $lines[] = sprintf('| %s | %s | %d / %d |', $name, self::percent($row), $row['covered'], $row['statements']);
        }

        return $lines;
    }

    /** @param list<string> $segments dossiers du fichier sous src/ */
    private function folderKey(array $segments): string
    {
        return $segments === [] ? '(racine)' : implode('/', array_slice($segments, 0, 2));
    }

    /**
     * @param array{covered: int, statements: int}|null $bucket
     * @param-out array{covered: int, statements: int}  $bucket
     */
    private function add(?array &$bucket, int $statements, int $covered): void
    {
        $bucket ??= ['covered' => 0, 'statements' => 0];
        $bucket['covered'] += $covered;
        $bucket['statements'] += $statements;
    }

    /** @param array{covered: int, statements: int} $row */
    private static function ratio(array $row): float
    {
        return $row['statements'] === 0 ? 0.0 : $row['covered'] / $row['statements'];
    }

    /** @param array{covered: int, statements: int} $row */
    private static function percent(array $row): string
    {
        return number_format(self::ratio($row) * 100, 1, ',', '') . ' %';
    }
}
