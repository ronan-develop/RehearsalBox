<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Group;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Service\Contract\GroupServiceInterface;

final class GroupService implements GroupServiceInterface
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {
    }

    public function create(string $name, ?string $genre, ?string $colorHex): Group
    {
        return $this->groupRepository->save(new Group(0, $name, $genre, $colorHex));
    }

    public function addMemberByEmail(int $groupId, string $email): void
    {
        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            throw new \InvalidArgumentException("Aucun compte n'existe avec l'email {$email}.");
        }

        $this->groupRepository->addMember($groupId, $user->id());
    }

    public function removeMember(int $groupId, int $userId): void
    {
        $this->groupRepository->removeMember($groupId, $userId);
    }

    public function findAll(): array
    {
        return $this->groupRepository->findAll();
    }
}
