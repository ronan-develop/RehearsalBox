<?php

declare(strict_types=1);

namespace App\Http;

class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        protected readonly string $body = '',
        protected readonly int $statusCode = 200,
        protected readonly array $headers = [],
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

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }
        echo $this->body;
    }
}
