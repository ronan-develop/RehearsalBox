<?php

declare(strict_types=1);

namespace App\Accounting\Todo;

/** Une tâche du trésorier est « à faire » (échéance pas dépassée) ou « en retard ». Une tâche faite ne figure plus dans la liste. */
enum TodoState: string
{
    case AFaire = 'a_faire';
    case EnRetard = 'en_retard';

    public function label(): string
    {
        return match ($this) {
            self::AFaire => 'À faire',
            self::EnRetard => 'En retard',
        };
    }
}
