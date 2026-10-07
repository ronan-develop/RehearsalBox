<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Group\Entity\LineupMember;
use App\Group\Entity\UpcomingShow;

/**
 * Règle de saisie d'un groupe et de son profil (#224) : tout ce qui entre est borné AVANT la base (une colonne trop courte
 * donnait une erreur 500), la couleur est strictement `#rrggbb` (elle finit dans un attribut `style`) et le profil, stocké en
 * JSON et décodé à chaque affichage de la page, ne peut pas devenir un énorme document.
 */
final class GroupInputPolicy
{
    private const MAX_NAME = 120;
    private const MAX_GENRE = 60;
    private const MAX_EMAIL = 190;
    private const MAX_MEMBERS = 20;
    private const MAX_SHOWS = 20;
    private const MAX_TEXT = 100;

    /** @return array<string, string> erreur par champ (name, genre, colorHex, contactEmail), vide si tout est valide */
    public function groupViolations(string $name, ?string $genre, ?string $colorHex, string $contactEmail): array
    {
        $errors = [];

        $name = $this->normalizedName($name);
        if ($name === '' || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            $errors['name'] = 'Le nom du groupe est requis (sans caractère de contrôle).';
        } elseif (mb_strlen($name) > self::MAX_NAME) {
            $errors['name'] = 'Le nom du groupe ne peut pas dépasser ' . self::MAX_NAME . ' caractères.';
        }

        if ($genre !== null && mb_strlen($genre) > self::MAX_GENRE) {
            $errors['genre'] = 'Le genre ne peut pas dépasser ' . self::MAX_GENRE . ' caractères.';
        }

        if ($colorHex !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $colorHex) !== 1) {
            $errors['colorHex'] = 'La couleur doit s\'écrire #rrggbb (par exemple #e63946).';
        }

        if (filter_var($contactEmail, FILTER_VALIDATE_EMAIL) === false || strlen($contactEmail) > self::MAX_EMAIL) {
            $errors['contactEmail'] = 'L\'adresse e-mail de contact est requise et doit être valide.';
        }

        return $errors;
    }

    /**
     * @param list<LineupMember> $lineup
     * @param list<UpcomingShow> $upcomingShows
     *
     * @return array<string, string> erreur par champ (lineup, upcomingShows), vide si tout est valide
     */
    public function profileViolations(array $lineup, array $upcomingShows): array
    {
        $errors = [];

        if (count($lineup) > self::MAX_MEMBERS) {
            $errors['lineup'] = 'La composition ne peut pas dépasser ' . self::MAX_MEMBERS . ' personnes.';
        } else {
            foreach ($lineup as $member) {
                if (!$this->isShortText($member->name()) || !$this->isShortText($member->instrument())) {
                    $errors['lineup'] = 'Chaque membre a un nom et un instrument de ' . self::MAX_TEXT . ' caractères au plus.';
                    break;
                }
            }
        }

        if (count($upcomingShows) > self::MAX_SHOWS) {
            $errors['upcomingShows'] = 'Les concerts ne peuvent pas dépasser ' . self::MAX_SHOWS . ' dates.';
        } else {
            foreach ($upcomingShows as $show) {
                if (!$this->isRealDay($show->date()) || !$this->isShortText($show->venue())) {
                    $errors['upcomingShows'] = 'Chaque concert a une vraie date (AAAA-MM-JJ) et une salle de ' . self::MAX_TEXT . ' caractères au plus.';
                    break;
                }
            }
        }

        return $errors;
    }

    /** Espaces de bord retirés, espaces internes réduits à un seul. */
    public function normalizedName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** Texte facultatif : null s'il est vide une fois nettoyé. */
    public function normalizedOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }

    private function isShortText(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && mb_strlen($text) <= self::MAX_TEXT && preg_match('/[\x00-\x1F\x7F]/u', $text) !== 1;
    }

    private function isRealDay(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false && $parsed->format('Y-m-d') === $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
