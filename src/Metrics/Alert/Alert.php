<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

/** Une alerte : son type et une phrase qui ne contient que des chiffres et des noms de mesures, jamais d'adresse ni de contenu. */
final class Alert
{
    public function __construct(
        public readonly AlertType $type,
        public readonly string $message,
    ) {
    }
}
