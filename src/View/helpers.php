<?php

declare(strict_types=1);

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
