<?php

declare(strict_types=1);

namespace App\Http;

final class JsonResponse extends Response
{
    /** @param array<string, mixed> $data @param array<string, string> $headers */
    public function __construct(array $data, int $statusCode = 200, array $headers = [])
    {
        parent::__construct(
            body: (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            statusCode: $statusCode,
            headers: $headers + ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
