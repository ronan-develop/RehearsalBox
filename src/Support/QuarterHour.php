<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Pas de 15 minutes des heures saisies pour une demande ou une réservation (#263) : couvre la demi-heure et le quart d'heure.
 * Fonctions pures : une heure valide s'écrit HH:MM ou HH:MM:SS, secondes à zéro.
 */
final class QuarterHour
{
    public const STEP_MINUTES = 15;

    public static function isAligned(string $time): bool
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::00)?$/', $time, $parts) !== 1) {
            return false;
        }

        return (int) $parts[2] % self::STEP_MINUTES === 0;
    }

    /** HH:MM ou HH:MM:SS → HH:MM:SS (format de la colonne TIME). À n'appeler que sur une heure alignée. */
    public static function normalise(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    /**
     * Tous les quarts d'heure de $first à $last inclus (HH:MM), pour remplir un sélecteur d'horaire.
     *
     * @return list<string>
     */
    public static function range(string $first, string $last): array
    {
        $times = [];
        for ($minutes = self::toMinutes($first); $minutes <= self::toMinutes($last); $minutes += self::STEP_MINUTES) {
            $times[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $times;
    }

    private static function toMinutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }
}
