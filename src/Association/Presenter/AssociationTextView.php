<?php

declare(strict_types=1);

namespace App\Association\Presenter;

use App\Association\Entity\AssociationText;

/** Données des pages « Textes de l'association » : texte brut, le gabarit échappe (#472). */
final class AssociationTextView
{
    /**
     * @param list<AssociationText> $texts
     * @return list<array{id: int, title: string, updated: string, by: string}>
     */
    public function rows(array $texts): array
    {
        return array_map(static fn (AssociationText $t): array => [
            'id' => $t->id,
            'title' => $t->title,
            'updated' => $t->updatedAt->format('d/m/Y'),
            'by' => $t->updatedByName,
        ], $texts);
    }

    /** @return array{id: int, title: string, body: string, bodyHtml: string, revision: int, updated: string, by: string} */
    public function show(AssociationText $text): array
    {
        return [
            'id' => $text->id,
            'title' => $text->title,
            'body' => $text->body,
            'bodyHtml' => (new AssociationTextFormatter())->html($text->body),
            'revision' => $text->revision,
            'updated' => $text->updatedAt->format('d/m/Y'),
            'by' => $text->updatedByName,
        ];
    }
}
