<?php

declare(strict_types=1);

namespace App\Assembly\Repository;

use App\Assembly\Entity\AssemblyContent;

/** Accès aux tables filles (bureau, présences, ordre du jour, résolutions) ; appelé dans la transaction de l'appelant. */
interface AssemblyContentRepositoryInterface
{
    public function load(int $assemblyId): AssemblyContent;

    /** Remplace tout le contenu (positions de 0 à n-1). */
    public function replace(int $assemblyId, AssemblyContent $content): void;

    /** Copie le contenu d'une version vers une autre (correction). */
    public function copy(int $fromId, int $toId): void;
}
