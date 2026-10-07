<?php

declare(strict_types=1);

namespace App\Routing;

final class MatchedRoute
{
    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly array $handler,
        public readonly array $params,
        /** Motif de la route (ex. « /api/conversations/{id} »), sans identifiant : la clé des mesures. */
        public readonly string $pattern = '',
    ) {
    }
}
