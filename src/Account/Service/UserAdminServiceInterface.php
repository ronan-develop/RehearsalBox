<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\AdminUserItem;
use App\Account\Entity\UserRole;
use App\Account\Entity\User;

interface UserAdminServiceInterface
{
    /** @return list<AdminUserItem> */
    public function listUsers(\DateTimeImmutable $now): array;

    /**
     * Crée un compte sans mot de passe connu, éventuellement rattaché à un groupe.
     *
     * @throws \App\Account\Exception\UserValidationException e-mail, nom ou groupe invalide
     */
    public function create(string $email, string $displayName, UserRole $role, ?int $groupId): User;

    /**
     * @throws \App\Account\Exception\UserNotFoundException
     * @throws \App\Account\Exception\UserAdminRuleException on ne se désactive pas soi-même, ni le dernier admin actif
     */
    public function setActive(int $userId, bool $active, int $actorUserId): User;

    /** @throws \App\Account\Exception\UserNotFoundException */
    public function unlock(int $userId): User;
}
