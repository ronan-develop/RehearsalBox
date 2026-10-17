<?php

declare(strict_types=1);

namespace App\Accounting\Export;

/**
 * Neutralisation des cellules d'un export CSV (#362) : un texte saisi par un utilisateur (libellé, note, nom) qui commencerait par `=`, `+`, `-`,
 * `@`, une tabulation ou un retour (même après des espaces) serait exécuté comme une formule à l'ouverture dans un tableur. On le fait précéder
 * d'une apostrophe : il s'affiche tel quel, il ne s'exécute pas. Les montants ne passent PAS par ici (ils sont formatés par l'exportateur).
 */
final class CsvSafe
{
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    public static function cell(string $text): string
    {
        $head = ltrim($text, " \u{a0}");
        if ($head !== '' && in_array($head[0], self::TRIGGERS, true)) {
            return "'" . $text;
        }

        return $text;
    }
}
