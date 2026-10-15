<?php

declare(strict_types=1);

namespace App\Association\Repository;

use App\Assembly\Entity\BoardPost;
use App\Association\Entity\BoardHolder;

/** Accès à la table `association_board` : au plus un titulaire par poste. */
interface AssociationBoardRepositoryInterface
{
    /** @return array<string, BoardHolder> titulaires par valeur de BoardPost */
    public function all(): array;

    /** Pose ou remplace le titulaire du poste, en une requête. */
    public function assign(BoardPost $post, int $userId, string $fullName, int $actorId, \DateTimeImmutable $now): void;

    /** Vide le poste ; sans effet s'il l'est déjà. */
    public function vacate(BoardPost $post): void;
}
