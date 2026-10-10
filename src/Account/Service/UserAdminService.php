<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\AdminUserItem;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Account\Security\LastAdminGuard;
use App\Account\Service\Throttle\LoginThrottle;
use App\Database\TransactionRunner;
use App\Account\Service\UserAdminServiceInterface;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;

final class UserAdminService implements UserAdminServiceInterface
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly UserProvisioningService $provisioning,
        private readonly LoginThrottle $loginThrottle,
        private readonly LastAdminGuard $lastAdminGuard,
        private readonly TransactionRunner $transactions,
    ) {
    }

    public function listUsers(\DateTimeImmutable $now): array
    {
        return array_map(function (User $user) use ($now): AdminUserItem {
            $groups = $this->sortedByName($this->groupRepository->findByMember($user->id()));
            $roles = [];
            foreach ($groups as $group) {
                $roles[$group->id()] = ($this->groupRepository->roleOf($group->id(), $user->id()) ?? \App\Group\Entity\GroupUserRole::Membre)->value;
            }

            return new AdminUserItem($user, $groups, $user->isLocked($now), $roles);
        }, $this->userRepository->findAll());
    }

    public function create(string $email, string $displayName, UserRole $role, ?int $groupId): User
    {
        // Le groupe est vérifié AVANT toute création : pas de compte à moitié créé.
        if ($groupId !== null && $this->groupRepository->findById($groupId) === null) {
            throw new UserValidationException(['groupId' => 'Groupe introuvable.']);
        }

        $user = $this->provisioning->createWithoutPassword($email, $displayName, $role);

        if ($groupId !== null) {
            $this->groupRepository->addMember($groupId, $user->id());
        }

        return $user;
    }

    public function setActive(int $userId, bool $active, int $actorUserId): User
    {
        // Une transaction : la garde « dernier administrateur » verrouille les administrateurs jusqu'à l'enregistrement (#272).
        return $this->transactions->run(function () use ($userId, $active, $actorUserId): User {
            $user = $this->userRepository->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
            if (!$active) {
                $this->lastAdminGuard->assertMayLoseAdmin($user, $actorUserId, 'désactiver');
            }

            return $this->userRepository->save($user->withActive($active));
        });
    }

    public function unlock(int $userId): User
    {
        $user = $this->userRepository->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");

        $this->userRepository->resetFailedLogins($user->id());
        // Le blocage annoncé à l'écran de connexion (#236) est tenu par identifiant : sans cela, « Débloquer » ne débloquerait rien.
        $this->loginThrottle->forgetIdentifier($user->email());

        return $this->userRepository->findById($user->id()) ?? $user;
    }

    /**
     * @param list<Group> $groups
     *
     * @return list<Group>
     */
    private function sortedByName(array $groups): array
    {
        usort($groups, static fn (Group $a, Group $b): int => strcmp($a->name(), $b->name()));

        return $groups;
    }
}
