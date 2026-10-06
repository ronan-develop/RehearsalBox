<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Enum\GroupUserRole;
use App\Repository\Contract\GroupManagerRepositoryInterface;

final class MysqlGroupManagerRepository implements GroupManagerRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function promoteToManager(int $groupId, int $userId): void
    {
        $this->updateRole($groupId, $userId, GroupUserRole::Gestionnaire);
    }

    public function demoteToMember(int $groupId, int $userId): void
    {
        $this->updateRole($groupId, $userId, GroupUserRole::Membre);
    }

    public function countManagers(int $groupId): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM group_user WHERE group_id = :group_id AND role = 'gestionnaire'"
        );
        $statement->execute(['group_id' => $groupId]);

        return (int) $statement->fetchColumn();
    }

    private function updateRole(int $groupId, int $userId, GroupUserRole $role): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE group_user SET role = :role WHERE group_id = :group_id AND user_id = :user_id'
        );
        $statement->execute(['role' => $role->value, 'group_id' => $groupId, 'user_id' => $userId]);
    }
}
