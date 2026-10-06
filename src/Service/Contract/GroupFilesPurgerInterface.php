<?php

declare(strict_types=1);

namespace App\Service\Contract;

/** Fichiers stockés d'un groupe (documents) : à lister AVANT de supprimer le groupe, à retirer APRÈS (#222). */
interface GroupFilesPurgerInterface
{
    /** @return list<string> chemins des fichiers stockés du groupe */
    public function filesOf(int $groupId): array;

    /** @param list<string> $paths retrait au mieux : un fichier déjà absent n'est jamais une erreur */
    public function remove(array $paths): void;
}
