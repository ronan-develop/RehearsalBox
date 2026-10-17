<?php

declare(strict_types=1);

namespace App\Accounting\Todo;

/**
 * Une tâche du trésorier, calculée à partir de l'état du livre (jamais stockée). `key` l'identifie de façon stable (type et mois), `url`
 * est une adresse du site (jamais une valeur de l'utilisateur). Le libellé ne porte ni montant, ni nom, ni donnée bancaire.
 */
final class TodoItem
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $action,
        public readonly string $url,
        public readonly TodoState $state,
        public readonly ?\DateTimeImmutable $dueOn,
    ) {
    }
}
