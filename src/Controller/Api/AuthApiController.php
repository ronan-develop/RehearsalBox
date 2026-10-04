<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AuthServiceInterface;
use App\Service\Exception\UserValidationException;
use App\Service\UserProvisioningService;

final class AuthApiController
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly UserProvisioningService $userProvisioning,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $email = (string) $request->body('email', '');
        $password = (string) $request->body('password', '');
        $displayName = (string) $request->body('displayName', '');

        try {
            $user = $this->userProvisioning->create($email, $displayName, UserRole::Musicien, $password);
        } catch (UserValidationException $e) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => $e->fields()], 422);
        }

        return new JsonResponse(['id' => $user->id(), 'email' => $user->email()], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $email = (string) $request->body('email', '');
        $password = (string) $request->body('password', '');

        $user = $this->authService->attempt($email, $password);

        if ($user === null) {
            return new JsonResponse(['error' => 'Identifiants invalides.'], 401);
        }

        $groupsToSelect = $this->authService->groupsRequiringSelection();
        if ($groupsToSelect !== []) {
            return new JsonResponse([
                'id' => $user->id(),
                'displayName' => $user->displayName(),
                'groupsToSelect' => array_map(
                    static fn (Group $group): array => ['id' => $group->id(), 'name' => $group->name()],
                    $groupsToSelect,
                ),
            ]);
        }

        return new JsonResponse(['id' => $user->id(), 'displayName' => $user->displayName()]);
    }

    public function selectGroup(Request $request): JsonResponse
    {
        $groupId = (int) $request->body('groupId', 0);

        try {
            $this->authService->selectActiveGroup($groupId);
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        return new JsonResponse(['status' => 'ok']);
    }
}
