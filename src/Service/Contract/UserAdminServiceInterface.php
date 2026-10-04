<?php

declare(strict_types=1);

namespace App\Service\Contract;

use App\Entity\AdminUserItem;
use App\Entity\Enum\UserRole;
use App\Entity\User;

interface UserAdminServiceInterface
{
    /** @return list<AdminUserItem> */
    public function listUsers(\DateTimeImmutable $now): array;

    /**
     * Crée un compte sans mot de passe connu, éventuellement rattaché à un groupe.
     *
     * @throws \App\Service\Exception\UserValidationException e-mail, nom ou groupe invalide
     */
    public function create(string $email, string $displayName, UserRole $role, ?int $groupId): User;

    /**
     * @throws \App\Service\Exception\UserNotFoundException
     * @throws \App\Service\Exception\UserAdminRuleException on ne se désactive pas soi-même, ni le dernier admin actif
     */
    public function setActive(int $userId, bool $active, int $actorUserId): User;

    /** @throws \App\Service\Exception\UserNotFoundException */
    public function unlock(int $userId): User;
}
