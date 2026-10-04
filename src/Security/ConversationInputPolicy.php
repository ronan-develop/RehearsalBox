<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Règles uniques de saisie de la messagerie (#153). Le sujet est affiché dans les listes et sur une seule ligne :
 * borné et sans caractère de contrôle ni de mise en forme. Le message est du texte brut (sauts de ligne permis).
 * Les longueurs sont en caractères, pas en octets.
 */
final class ConversationInputPolicy
{
    public const MAX_SUBJECT_LENGTH = 150;
    public const MAX_BODY_LENGTH = 5000;

    public function normalize(string $text): string
    {
        return trim($text);
    }

    /**
     * @param string|null $subject null pour une réponse (pas de sujet)
     *
     * @return array<string, string> message d'erreur par champ ('subject', 'message'), vide si tout est valide
     */
    public function violations(?string $subject, string $body): array
    {
        $errors = [];

        if ($subject !== null) {
            if ($subject === '') {
                $errors['subject'] = 'Le sujet est requis.';
            } elseif (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH) {
                $errors['subject'] = 'Le sujet ne doit pas dépasser ' . self::MAX_SUBJECT_LENGTH . ' caractères.';
            } elseif (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $subject) === 1) {
                $errors['subject'] = 'Le sujet contient des caractères non autorisés.';
            }
        }

        if ($body === '') {
            $errors['message'] = 'Le message est requis.';
        } elseif (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            $errors['message'] = 'Le message ne doit pas dépasser ' . self::MAX_BODY_LENGTH . ' caractères.';
        }

        return $errors;
    }
}
