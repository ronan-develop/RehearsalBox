<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Group\Entity\Group;

/** Une ligne de la page admin des utilisateurs : le compte, ses groupes (avec son rôle dans chacun), et son état de verrouillage. */
final class AdminUserItem
{
    /**
     * @param list<Group>           $groups
     * @param array<int, string>    $groupRoles identifiant de groupe => 'membre' | 'gestionnaire'
     */
    public function __construct(
        private readonly User $user,
        private readonly array $groups,
        private readonly bool $isLocked,
        private readonly array $groupRoles = [],
    ) {
    }

    public function user(): User
    {
        return $this->user;
    }

    /** @return list<Group> */
    public function groups(): array
    {
        return $this->groups;
    }

    /** Rôle de la personne dans ce groupe ('membre' | 'gestionnaire'), null si elle n'en est pas membre. */
    public function groupRole(int $groupId): ?string
    {
        return $this->groupRoles[$groupId] ?? null;
    }

    public function isLocked(): bool
    {
        return $this->isLocked;
    }
}
