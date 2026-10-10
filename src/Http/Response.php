<?php

declare(strict_types=1);

namespace App\Http;

class Response
{
    /**
     * Statut de TOUTES les limites de débit (connexion, mot de passe oublié, messages) : 423 et non 429. Sur l'hébergeur, un 429 à des
     * POST répétés est bloqué (la première réponse passe, les suivantes restent suspendues : écran qui tourne sans fin), même pour un
     * script de cinq lignes ; 423 passe normalement (#368). Ne jamais réintroduire un 429 sans le re-tester en production.
     */
    public const RATE_LIMITED = 423;

    /** @param array<string, string> $headers */
    public function __construct(
        protected readonly string $body = '',
        protected readonly int $statusCode = 200,
        protected array $headers = [],
    ) {
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Copie de la réponse avec des en-têtes par défaut : ceux que la réponse porte déjà (quelle que soit la casse du nom) sont conservés.
     *
     * @param array<string, string> $defaults
     */
    public function withDefaultHeaders(array $defaults): static
    {
        $present = array_map('strtolower', array_keys($this->headers));
        $copy = clone $this;
        foreach ($defaults as $name => $value) {
            if (!in_array(strtolower($name), $present, true)) {
                $copy->headers[$name] = $value;
            }
        }

        return $copy;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
