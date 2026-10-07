<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Group\Entity\Group;
use App\Group\Entity\LineupMember;
use App\Group\Entity\UpcomingShow;

interface GroupServiceInterface
{
    public function create(string $name, ?string $genre, ?string $colorHex, string $contactEmail): Group;

    /** @throws \InvalidArgumentException si le groupe n'existe pas */
    public function update(int $groupId, string $name, ?string $genre, ?string $colorHex, string $contactEmail): Group;

    public function delete(int $groupId): void;

    /** @throws \InvalidArgumentException si aucun compte n'existe avec cet email */
    public function addMemberByEmail(int $groupId, string $email): void;

    public function removeMember(int $groupId, int $userId): void;

    /** @return list<Group> */
    public function findAll(): array;

    /**
     * @param list<LineupMember> $lineup
     * @param list<UpcomingShow> $upcomingShows
     * @throws \App\Security\Exception\AccessDeniedException si $actorUserId n'est pas gestionnaire du groupe
     */
    public function updateProfile(int $groupId, array $lineup, array $upcomingShows, int $actorUserId): Group;
}
