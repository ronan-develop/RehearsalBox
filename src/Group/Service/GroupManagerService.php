<?php

declare(strict_types=1);

namespace App\Group\Service;

use App\Group\Entity\GroupUserRole;
use App\Group\Repository\GroupManagerRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Security\Exception\AccessDeniedException;

/**
 * Rôle de gestionnaire d'un groupe : un gestionnaire en promeut ou en rétrograde un autre, jamais le dernier. Aucune route ne
 * l'expose encore (le premier gestionnaire d'un groupe est créé hors de l'interface) : à brancher quand le propriétaire aura
 * décidé comment on devient gestionnaire.
 */
final class GroupManagerService
{
    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly GroupManagerRepositoryInterface $managers,
    ) {
    }

    public function promoteMember(int $groupId, int $userId, int $actorUserId): void
    {
        $this->assertActorIsManager($groupId, $actorUserId);

        $this->managers->promoteToManager($groupId, $userId);
    }

    public function demoteMember(int $groupId, int $userId, int $actorUserId): void
    {
        $this->assertActorIsManager($groupId, $actorUserId);

        if ($this->groupRepository->roleOf($groupId, $userId) === GroupUserRole::Gestionnaire
            && $this->managers->countManagers($groupId) <= 1
        ) {
            throw new \LogicException('Impossible de rétrograder le dernier gestionnaire du groupe.');
        }

        $this->managers->demoteToMember($groupId, $userId);
    }

    private function assertActorIsManager(int $groupId, int $actorUserId): void
    {
        if ($this->groupRepository->roleOf($groupId, $actorUserId) !== GroupUserRole::Gestionnaire) {
            throw new AccessDeniedException("Vous n'êtes pas gestionnaire de ce groupe.");
        }
    }
}
