<?php

declare(strict_types=1);

namespace App\Assembly\Entity;

/** Une ligne du bureau : le poste, le compte titulaire et son nom recopié à la saisie. */
final class BoardMember
{
    public function __construct(
        public readonly BoardPost $post,
        public readonly int $userId,
        public readonly string $name,
    ) {
    }
}
