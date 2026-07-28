<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Group;

interface GroupRepositoryInterface
{
    public function findById(int $id): ?Group;

    /** @return list<Group> */
    public function findAll(): array;

    /** @return list<Group> */
    public function findByMember(int $userId): array;

    public function save(Group $group): Group;

    public function delete(int $id): void;

    public function addMember(int $groupId, int $userId, GroupUserRole $role = GroupUserRole::Membre): void;

    public function removeMember(int $groupId, int $userId): void;

    public function isMember(int $groupId, int $userId): bool;

    public function roleOf(int $groupId, int $userId): ?GroupUserRole;

    public function promoteToManager(int $groupId, int $userId): void;

    public function demoteToMember(int $groupId, int $userId): void;

    public function countManagers(int $groupId): int;
}
