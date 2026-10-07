<?php

declare(strict_types=1);

namespace App\Messaging\Service;

/**
 * Règles uniques de saisie de la messagerie (#153, #169). Le titre (facultatif) est affiché dans les listes et sur une seule ligne :
 * borné et sans caractère de contrôle ni de mise en forme. Le message est du texte brut (sauts de ligne permis).
 * Les longueurs sont en caractères, pas en octets.
 */
final class ConversationInputPolicy
{
    public const MAX_TITLE_LENGTH = 150;
    public const MAX_BODY_LENGTH = 5000;

    public function normalize(string $text): string
    {
        return trim($text);
    }

    /** Message d'erreur si le titre (déjà normalisé et non vide) est refusé, null sinon. */
    public function titleViolation(string $title): ?string
    {
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return 'Le titre ne doit pas dépasser ' . self::MAX_TITLE_LENGTH . ' caractères.';
        }
        if (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $title) === 1) {
            return 'Le titre contient des caractères non autorisés.';
        }

        return null;
    }

    /** Message d'erreur si le message (déjà normalisé) est refusé, null sinon. */
    public function bodyViolation(string $body): ?string
    {
        if ($body === '') {
            return 'Le message est requis.';
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            return 'Le message ne doit pas dépasser ' . self::MAX_BODY_LENGTH . ' caractères.';
        }

        return null;
    }
}
