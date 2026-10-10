<?php

declare(strict_types=1);

namespace App\Group\Controller\Api;

use App\Account\Entity\UserRole;
use App\Account\Exception\UserValidationException;
use App\Group\Entity\GroupUserRole;
use App\Group\Service\GroupMembershipAdminService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * Appartenances d'un compte aux groupes (#272), depuis la page des comptes. Admin uniquement. L'acteur vient de la session ; les
 * identifiants de l'adresse sont strictement numériques (sinon refus, comme partout) ; réponses 204 sans corps, la page recharge sa liste.
 */
final class UserGroupAdminApiController
{
    public function __construct(
        private readonly GroupMembershipAdminService $memberships,
        private readonly AuthGuard $authGuard,
    ) {
    }

    /** Ajoute le compte au groupe avec ce rôle, ou change son rôle s'il en est déjà membre. */
    public function set(Request $request, string $id, string $groupId): JsonResponse
    {
        $actor = $this->authGuard->requireRole(UserRole::Admin);
        $userId = StrictId::orDenied($id);
        $group = StrictId::orDenied($groupId);

        $value = $request->body('role');
        $role = is_string($value) ? GroupUserRole::tryFrom($value) : null;
        if ($role === null) {
            throw new UserValidationException(['role' => 'Rôle invalide (membre ou gestionnaire).']);
        }
        $this->memberships->setMembership($userId, $group, $role, $actor->id());

        return new JsonResponse([], 204);
    }

    public function remove(Request $request, string $id, string $groupId): JsonResponse
    {
        $actor = $this->authGuard->requireRole(UserRole::Admin);

        $this->memberships->removeMembership(StrictId::orDenied($id), StrictId::orDenied($groupId), $actor->id());

        return new JsonResponse([], 204);
    }

    /** Change le compte de groupe : retrait du groupe de départ et ajout comme membre à `toGroupId`, en une transaction. */
    public function move(Request $request, string $id, string $groupId): JsonResponse
    {
        $actor = $this->authGuard->requireRole(UserRole::Admin);
        $userId = StrictId::orDenied($id);
        $from = StrictId::orDenied($groupId);

        $to = StrictId::from($request->body('toGroupId'));
        if ($to === null) {
            throw new UserValidationException(['groupId' => 'Groupe d\'arrivée invalide.']);
        }
        $this->memberships->moveMembership($userId, $from, $to, $actor->id());

        return new JsonResponse([], 204);
    }
}
