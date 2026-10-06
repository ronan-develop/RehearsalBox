<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @param array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $files
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $files = [],
        private readonly string $clientIp = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = (string) $value;
            }
        }

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            files: $_FILES,
            clientIp: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    /** Adresse de la connexion TCP (jamais un en-tête fourni par le client, falsifiable) ; vide si absente ou invalide. */
    public function clientIp(): string
    {
        return filter_var($this->clientIp, FILTER_VALIDATE_IP) !== false ? $this->clientIp : '';
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function allBody(): array
    {
        return $this->body;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtoupper($name)] ?? $this->headers[$name] ?? $default;
    }

    /** Le navigateur charge la page à l'avance (survol, prérendu) sans que la personne l'ouvre : ne doit rien « consommer ». */
    public function isPrefetch(): bool
    {
        foreach (['Sec-Purpose', 'Purpose', 'X-Moz'] as $name) {
            if (str_contains(strtolower($this->header($name) ?? ''), 'prefetch')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }
}
