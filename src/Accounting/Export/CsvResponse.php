<?php

declare(strict_types=1);

namespace App\Accounting\Export;

use App\Http\Response;

/**
 * Réponse CSV à la française pour un tableur (#362) : séparateur `;`, UTF-8 avec BOM, guillemets et retours à la ligne protégés (fputcsv).
 * Les lignes viennent d'un itérateur et sont écrites au fil de l'eau (jamais tout le fichier en mémoire à l'envoi). Pièce jointe au nom FIXE
 * choisi par le serveur (jamais une valeur de l'utilisateur), `nosniff`, jamais mis en cache.
 */
final class CsvResponse extends Response
{
    /** @param iterable<list<string>> $rows */
    public function __construct(private readonly iterable $rows, string $filename)
    {
        parent::__construct('', 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function body(): string
    {
        $out = fopen('php://temp', 'w+');
        if ($out === false) {
            return '';
        }
        $this->write($out);
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function send(): void
    {
        $this->sendHeaders();
        $out = fopen('php://output', 'w');
        if ($out !== false) {
            $this->write($out);
            fclose($out);
        }
    }

    /** @param resource $out */
    private function write($out): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($this->rows as $row) {
            fputcsv($out, $row, ';', '"', '', "\r\n");
        }
    }
}
