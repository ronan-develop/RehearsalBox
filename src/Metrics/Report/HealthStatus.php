<?php

declare(strict_types=1);

namespace App\Metrics\Report;

/** L'état d'un indicateur. Toujours montré avec une icône ET un libellé : jamais la couleur seule. */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Normal',
            self::Warning => 'À surveiller',
            self::Critical => 'Critique',
            self::Unknown => 'Inconnu',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Ok => '✓',
            self::Warning => '⚠',
            self::Critical => '✕',
            self::Unknown => '–',
        };
    }
}
