<?php

declare(strict_types=1);

namespace App\Association\Service;

use App\Association\Exception\AssociationTextValidationException;

/**
 * Titre et texte d'un texte de l'association (#472) : validés AVANT la base, toutes les erreurs remontées ensemble par champ.
 * Le texte est seulement rogné, ses fins de ligne normalisées et borné ; jamais transformé : l'échappement se fait à l'affichage.
 */
final class AssociationTextInputPolicy
{
    public const TITLE_MAX = 150;
    public const BODY_MAX = 100000;

    /** Caractère de contrôle interdit dans un titre (le retour à la ligne aussi). */
    private const TITLE_FORBIDDEN = '/[\x00-\x1F\x7F]/u';

    /** Caractère de contrôle interdit dans un texte : le retour à la ligne et la tabulation restent permis. */
    private const BODY_FORBIDDEN = '/[\x00-\x08\x0B-\x1F\x7F]/u';

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string} titre, texte
     * @throws AssociationTextValidationException
     */
    public function parse(array $input): array
    {
        $errors = [];
        $title = $this->field($input['title'] ?? null, 'title', self::TITLE_MAX, self::TITLE_FORBIDDEN, $errors);
        $body = $this->field($input['body'] ?? null, 'body', self::BODY_MAX, self::BODY_FORBIDDEN, $errors);
        if ($errors !== [] || $title === null || $body === null) {
            throw new AssociationTextValidationException($errors);
        }

        return [$title, $body];
    }

    /** @param array<string, string> $errors */
    private function field(mixed $value, string $key, int $max, string $forbidden, array &$errors): ?string
    {
        $clean = is_string($value) ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : '';
        if ($clean === '') {
            $errors[$key] = $key === 'title' ? 'Le titre est obligatoire.' : 'Le texte est obligatoire.';

            return null;
        }
        if (mb_strlen($clean) > $max) {
            $errors[$key] = 'Ce champ ne peut pas dépasser ' . $max . ' caractères.';

            return null;
        }
        if (preg_match($forbidden, $clean) === 1) {
            $errors[$key] = 'Ce champ contient un caractère non autorisé.';

            return null;
        }

        return $clean;
    }
}
