<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\Group;

interface GroupServiceInterface
{
    public function create(string $name, ?string $genre, ?string $colorHex): Group;

    /** @throws \InvalidArgumentException si aucun compte n'existe avec cet email */
    public function addMemberByEmail(int $groupId, string $email): void;

    public function removeMember(int $groupId, int $userId): void;

    /** @return list<Group> */
    public function findAll(): array;
}
