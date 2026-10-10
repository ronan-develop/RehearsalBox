<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\AdminUserItem;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Repository\GroupRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Account\Service\Throttle\LoginThrottle;
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
    ) {
    }

    public function listUsers(\DateTimeImmutable $now): array
    {
        return array_map(
            fn (User $user): AdminUserItem => new AdminUserItem(
                $user,
                $this->sortedByName($this->groupRepository->findByMember($user->id())),
                $user->isLocked($now),
            ),
            $this->userRepository->findAll(),
        );
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
        $user = $this->userRepository->findById($userId) ?? throw new UserNotFoundException("Utilisateur {$userId} introuvable.");

        if (!$active) {
            if ($userId === $actorUserId) {
                throw new UserAdminRuleException('Vous ne pouvez pas désactiver votre propre compte.');
            }
            if ($user->isActive() && $user->hasRole(UserRole::Admin) && $this->userRepository->countActiveAdmins() <= 1) {
                throw new UserAdminRuleException('Impossible de désactiver le dernier administrateur actif.');
            }
        }

        return $this->userRepository->save($user->withActive($active));
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
