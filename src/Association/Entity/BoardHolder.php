<?php

declare(strict_types=1);

namespace App\Association\Entity;

use App\Assembly\Entity\BoardPost;

/** Titulaire d'un poste du bureau (#478) : le compte, et le prénom et nom à imprimer dans les comptes rendus. */
final class BoardHolder
{
    public function __construct(
        public readonly BoardPost $post,
        public readonly int $userId,
        public readonly string $fullName,
    ) {
    }
}
