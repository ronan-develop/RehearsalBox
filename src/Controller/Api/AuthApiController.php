<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Group\Entity\Group;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Support\StrictId;
use App\Service\Contract\AuthServiceInterface;
use App\Service\IpThrottle;

final class AuthApiController
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly IpThrottle $loginThrottle,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $email = (string) $request->body('email', '');
        $password = (string) $request->body('password', '');

        $now = new \DateTimeImmutable();
        $ip = $request->clientIp();

        // Limite par adresse, avant de toucher à un compte : la réponse ne dépend d'aucun compte (rien à énumérer).
        if ($this->loginThrottle->isBlocked($ip, $now)) {
            return new JsonResponse(
                ['error' => 'Trop de tentatives. Réessayez dans quelques minutes.'],
                429,
                ['Retry-After' => (string) $this->loginThrottle->retryAfterSeconds()],
            );
        }

        $user = $this->authService->attempt($email, $password);

        if ($user === null) {
            $this->loginThrottle->record($ip, $now);

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
        $groupId = StrictId::from($request->body('groupId')) ?? 0;

        $this->authService->selectActiveGroup($groupId);

        return new JsonResponse(['status' => 'ok']);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        return new JsonResponse(['status' => 'ok']);
    }
}
