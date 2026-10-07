<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Group;
use App\Repository\Contract\GroupRepositoryInterface;

/** Dépôt de groupes de test : délègue et compte les lectures par identifiant (garde-fou contre les requêtes en boucle, #239). */
final class CountingGroupRepository implements GroupRepositoryInterface
{
    public int $findByIdCalls = 0;

    public function __construct(private readonly GroupRepositoryInterface $inner)
    {
    }

    public function findById(int $id): ?Group
    {
        ++$this->findByIdCalls;

        return $this->inner->findById($id);
    }

    public function findBySlug(string $slug): ?Group
    {
        return $this->inner->findBySlug($slug);
    }

    public function findAll(): array
    {
        return $this->inner->findAll();
    }

    public function findByMember(int $userId): array
    {
        return $this->inner->findByMember($userId);
    }

    public function save(Group $group): Group
    {
        return $this->inner->save($group);
    }

    public function delete(int $id): void
    {
        $this->inner->delete($id);
    }

    public function addMember(int $groupId, int $userId, GroupUserRole $role = GroupUserRole::Membre): void
    {
        $this->inner->addMember($groupId, $userId, $role);
    }

    public function removeMember(int $groupId, int $userId): void
    {
        $this->inner->removeMember($groupId, $userId);
    }

    public function isMember(int $groupId, int $userId): bool
    {
        return $this->inner->isMember($groupId, $userId);
    }

    public function roleOf(int $groupId, int $userId): ?GroupUserRole
    {
        return $this->inner->roleOf($groupId, $userId);
    }
}
