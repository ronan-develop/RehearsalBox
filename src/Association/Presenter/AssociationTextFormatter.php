<?php

declare(strict_types=1);

namespace App\Association\Presenter;

/**
 * Mise en forme d'un texte de l'association (#490), sans bibliothèque : une ligne « ## sous-titre » est un sous-titre (le titre du texte a son propre champ),
 * le reste du texte forme des paragraphes (une ligne vide les sépare, un retour à la ligne devient un saut). Chaque ligne est échappée
 * AVANT d'être enveloppée dans sa balise : aucun HTML saisi n'est jamais interprété.
 */
final class AssociationTextFormatter
{
    public function html(string $body): string
    {
        $html = '';
        $paragraph = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (trim($line) !== '' && preg_match('/\A## (\S.*)\z/u', $line, $match) !== 1) {
                $paragraph[] = self::escape(trim($line));

                continue;
            }
            $html .= self::paragraph($paragraph);
            $paragraph = [];
            if (isset($match[1])) {
                $html .= '<h2 class="rb-text-subheading">' . self::escape(trim($match[1])) . '</h2>';
            }
            unset($match);
        }

        return $html . self::paragraph($paragraph);
    }

    /** @param list<string> $lines lignes déjà échappées */
    private static function paragraph(array $lines): string
    {
        return $lines === [] ? '' : '<p>' . implode('<br>', $lines) . '</p>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
