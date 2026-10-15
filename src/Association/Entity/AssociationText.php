<?php

declare(strict_types=1);

namespace App\Association\Entity;

/** Un texte de l'association (statuts, règlement intérieur…) tel que lu : le dernier modificateur est donné par son nom affiché (#472). */
final class AssociationText
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $body,
        public readonly int $revision,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly string $updatedByName,
    ) {
    }
}
