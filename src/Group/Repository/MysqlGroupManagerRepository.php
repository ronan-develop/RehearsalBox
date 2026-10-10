<?php

declare(strict_types=1);

namespace App\Group\Repository;

use App\Group\Entity\GroupUserRole;
use App\Group\Repository\GroupManagerRepositoryInterface;

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

    public function lockManagerIds(int $groupId): array
    {
        $statement = $this->pdo->prepare("SELECT user_id FROM group_user WHERE group_id = :group_id AND role = 'gestionnaire' ORDER BY user_id FOR UPDATE");
        $statement->execute(['group_id' => $groupId]);

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function updateRole(int $groupId, int $userId, GroupUserRole $role): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE group_user SET role = :role WHERE group_id = :group_id AND user_id = :user_id'
        );
        $statement->execute(['role' => $role->value, 'group_id' => $groupId, 'user_id' => $userId]);
    }
}
