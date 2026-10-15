<?php

declare(strict_types=1);

namespace App\Association\Repository;

use App\Association\Entity\AssociationText;
use App\Association\Exception\AssociationTextValidationException;

/** Accès à la table `association_texts`. L'unicité du titre est garantie par la base (clé unique), pas par un « vérifier puis écrire ». */
interface AssociationTextRepositoryInterface
{
    /** @throws AssociationTextValidationException titre déjà pris */
    public function insert(string $title, string $body, int $actorId, \DateTimeImmutable $now): int;

    public function find(int $id): ?AssociationText;

    /** @return list<AssociationText> par titre */
    public function listAll(): array;

    /**
     * Faux si le texte n'existe pas ou si sa révision a changé.
     *
     * @throws AssociationTextValidationException titre déjà pris par un autre texte
     */
    public function update(int $id, int $expectedRevision, string $title, string $body, int $actorId, \DateTimeImmutable $now): bool;

    public function delete(int $id): bool;
}
