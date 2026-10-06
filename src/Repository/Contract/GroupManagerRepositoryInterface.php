<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/** Rôle de gestionnaire d'un groupe (peut envoyer des documents et modifier le profil) : promotion, rétrogradation, décompte. */
interface GroupManagerRepositoryInterface
{
    public function promoteToManager(int $groupId, int $userId): void;

    public function demoteToMember(int $groupId, int $userId): void;

    public function countManagers(int $groupId): int;
}
