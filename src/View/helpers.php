<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /** Échappement HTML systématique — jamais de <?= $value ?> brut dans les templates (cf. plan §10.2). */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
