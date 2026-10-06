<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Réponse qui envoie un fichier en FLUX (`readfile`) : jamais chargé en entier en mémoire. Un fichier introuvable est refusé dès
 * la construction (l'appelant répond alors 404) plutôt que d'envoyer une réponse vide. `body()` relit le fichier pour les
 * tests et outils ; l'envoi réel ne l'appelle pas.
 */
final class FileResponse extends Response
{
    /** @param array<string, string> $headers */
    public function __construct(private readonly string $path, string $contentType, array $headers = [])
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false) {
            throw new \RuntimeException('Fichier introuvable.');
        }

        parent::__construct('', 200, $headers + ['Content-Type' => $contentType, 'Content-Length' => (string) $size]);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function body(): string
    {
        return (string) file_get_contents($this->path);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        readfile($this->path);
    }
}
