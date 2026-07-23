<?php

declare(strict_types=1);

namespace App\Repository\Contract;

use App\Entity\Group;

interface GroupRepositoryInterface
{
    public function findById(int $id): ?Group;

    /** @return list<Group> */
    public function findAll(): array;

    public function save(Group $group): Group;

    public function addMember(int $groupId, int $userId): void;

    public function removeMember(int $groupId, int $userId): void;

    public function isMember(int $groupId, int $userId): bool;
}
