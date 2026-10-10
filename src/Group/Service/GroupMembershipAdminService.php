<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;
use App\Account\Repository\UserRepositoryInterface;
use App\Database\TransactionRunner;
use App\Group\Entity\GroupUserRole;
use App\Group\Exception\LastGroupManagerException;
use App\Group\Repository\GroupManagerRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Appartenances d'un compte aux groupes, décidées par un administrateur (#272) : ajouter, changer le rôle (membre / gestionnaire),
 * retirer, déplacer. Un compte peut appartenir à plusieurs groupes, ou à aucun. Un groupe ne reste jamais sans gestionnaire (règle
 * unique `GroupManagerService::assertNotLastManager`, verrouillée dans la transaction). Chaque changement laisse une ligne de journal
 * (identifiants seulement). Retirer quelqu'un d'un groupe coupe son accès aux conversations du groupe (calculé à la lecture) ; ses
 * messages restent, et une personne invitée dans la conversation en garde l'accès.
 */
final class GroupMembershipAdminService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly GroupRepositoryInterface $groups,
        private readonly GroupManagerRepositoryInterface $managers,
        private readonly GroupManagerService $managerRules,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ajoute le compte au groupe avec ce rôle, ou change son rôle s'il en est déjà membre.
     *
     * @throws UserNotFoundException
     * @throws UserValidationException     groupe introuvable
     * @throws LastGroupManagerException   rétrogradation du dernier gestionnaire
     */
    public function setMembership(int $userId, int $groupId, GroupUserRole $role, int $actorUserId): void
    {
        $changed = $this->transactions->run(function () use ($userId, $groupId, $role): bool {
            $this->assertUserAndGroupExist($userId, $groupId);
            $current = $this->groups->roleOf($groupId, $userId);
            if ($current === $role) {
                return false;
            }
            if ($current === null) {
                $this->groups->addMember($groupId, $userId, $role);

                return true;
            }
            if ($role === GroupUserRole::Membre) {
                $this->managerRules->assertNotLastManager($groupId, $userId);
                $this->managers->demoteToMember($groupId, $userId);
            } else {
                $this->managers->promoteToManager($groupId, $userId);
            }

            return true;
        });

        if ($changed) {
            $this->logger->info('Administration : appartenance modifiée', ['actor' => $actorUserId, 'user' => $userId, 'group' => $groupId, 'role' => $role->value]);
        }
    }

    /**
     * Retire le compte du groupe (sans effet s'il n'en est pas membre).
     *
     * @throws UserNotFoundException
     * @throws UserValidationException    groupe introuvable
     * @throws LastGroupManagerException  retrait du dernier gestionnaire
     */
    public function removeMembership(int $userId, int $groupId, int $actorUserId): void
    {
        $removed = $this->transactions->run(function () use ($userId, $groupId): bool {
            $this->assertUserAndGroupExist($userId, $groupId);
            if (!$this->groups->isMember($groupId, $userId)) {
                return false;
            }
            $this->managerRules->assertNotLastManager($groupId, $userId);
            $this->groups->removeMember($groupId, $userId);

            return true;
        });

        if ($removed) {
            $this->logger->info('Administration : appartenance retirée', ['actor' => $actorUserId, 'user' => $userId, 'group' => $groupId]);
        }
    }

    /**
     * Change le compte de groupe : retrait du groupe de départ et ajout comme membre au groupe d'arrivée, en UNE transaction.
     *
     * @throws UserNotFoundException
     * @throws UserValidationException    groupes identiques ou introuvables, compte non membre du groupe de départ
     * @throws LastGroupManagerException  le compte est le dernier gestionnaire du groupe de départ
     */
    public function moveMembership(int $userId, int $fromGroupId, int $toGroupId, int $actorUserId): void
    {
        $this->transactions->run(function () use ($userId, $fromGroupId, $toGroupId): void {
            $this->assertUserAndGroupExist($userId, $fromGroupId);
            $this->assertUserAndGroupExist($userId, $toGroupId);
            if ($fromGroupId === $toGroupId || !$this->groups->isMember($fromGroupId, $userId)) {
                throw new UserValidationException(['groupId' => 'Déplacement impossible : choisissez un autre groupe que celui de départ, dont la personne est membre.']);
            }
            $this->managerRules->assertNotLastManager($fromGroupId, $userId);
            $this->groups->removeMember($fromGroupId, $userId);
            $this->groups->addMember($toGroupId, $userId);
        });

        $this->logger->info('Administration : appartenance déplacée', ['actor' => $actorUserId, 'user' => $userId, 'from' => $fromGroupId, 'to' => $toGroupId]);
    }

    private function assertUserAndGroupExist(int $userId, int $groupId): void
    {
        if ($this->users->findById($userId) === null) {
            throw new UserNotFoundException("Utilisateur {$userId} introuvable.");
        }
        if ($this->groups->findById($groupId) === null) {
            throw new UserValidationException(['groupId' => 'Groupe introuvable.']);
        }
    }
}
