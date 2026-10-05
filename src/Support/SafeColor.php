<?php

declare(strict_types=1);

namespace App\Support;

/** Une couleur stockée n'est jamais émise dans un style ou un attribut que si c'est exactement #rrggbb : pas d'injection CSS ni HTML. */
final class SafeColor
{
    public static function from(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1 ? $value : null;
    }
}
