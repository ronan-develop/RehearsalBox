<?php

declare(strict_types=1);

use App\Entity\Enum\Weekday;

if (!function_exists('e')) {
    /** Échappement HTML systématique — jamais de <?= $value ?> brut dans les templates (cf. plan §10.2). */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('formatTime')) {
    /** Affichage HH:MM d'une heure stockée en HH:MM:SS (colonnes TIME de recurring_slots). */
    function formatTime(string $time): string
    {
        return substr($time, 0, 5);
    }
}

if (!function_exists('formatWeekday')) {
    /** Traduction en français — Weekday::name renvoie le nom du cas PHP en anglais. */
    function formatWeekday(Weekday $weekday): string
    {
        return match ($weekday) {
            Weekday::Monday => 'Lundi',
            Weekday::Tuesday => 'Mardi',
            Weekday::Wednesday => 'Mercredi',
            Weekday::Thursday => 'Jeudi',
            Weekday::Friday => 'Vendredi',
            Weekday::Saturday => 'Samedi',
            Weekday::Sunday => 'Dimanche',
        };
    }
}
