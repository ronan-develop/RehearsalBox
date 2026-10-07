<?php

declare(strict_types=1);

namespace App\Entity;

use App\Group\Entity\Group;

/** Une ligne de la page admin des utilisateurs : le compte, ses groupes, et son état de verrouillage. */
final class AdminUserItem
{
    /** @param list<Group> $groups */
    public function __construct(
        private readonly User $user,
        private readonly array $groups,
        private readonly bool $isLocked,
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

    public function isLocked(): bool
    {
        return $this->isLocked;
    }
}
