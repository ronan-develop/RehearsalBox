<?php

declare(strict_types=1);

namespace App\Accounting\Entity;

/** Adhérent vu par la comptabilité : un compte actif, désigné par son identifiant (jamais par un nom saisi en texte). */
final class Member
{
    public function __construct(
        public readonly int $id,
        public readonly string $displayName,
    ) {
    }
}
