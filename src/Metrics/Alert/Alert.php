<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

/**
 * Une alerte : son type, une phrase qui ne contient que des chiffres et des noms de mesures, jamais d'adresse ni de contenu, et la page
 * du site où l'on en voit le détail (les mesures par défaut, l'exploitation pour les sauvegardes).
 */
final class Alert
{
    public function __construct(
        public readonly AlertType $type,
        public readonly string $message,
        public readonly string $page = '/admin/metrics',
    ) {
    }
}
