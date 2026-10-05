<?php

declare(strict_types=1);

namespace App\Http;

class Response
{
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
