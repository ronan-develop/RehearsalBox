<?php

declare(strict_types=1);

namespace App\Account\Controller\Api;

use App\Group\Entity\Group;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Support\StrictId;
use App\Account\Service\AuthServiceInterface;
use App\Account\Service\Throttle\LoginThrottle;

final class AuthApiController
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly LoginThrottle $loginThrottle,
    ) {
    }

    public function login(Request $request): JsonResponse
    {
        $email = (string) $request->body('email', '');
        $password = (string) $request->body('password', '');

        $now = new \DateTimeImmutable();
        $ip = $request->clientIp();

        // Limites par adresse ET par identifiant saisi, avant de toucher à un compte : la réponse (message et durée) ne dépend d'aucun
        // compte, un compte réel et une adresse inventée sont indiscernables (rien à énumérer, aucun hachage dépensé).
        $remaining = $this->loginThrottle->remainingSeconds($ip, $email, $now);
        if ($remaining > 0) {
            return $this->tooManyAttempts($remaining);
        }

        $user = $this->authService->attempt($email, $password);

        if ($user === null) {
            $this->loginThrottle->recordFailure($ip, $email, $now);

            return new JsonResponse(['error' => 'Identifiants invalides.'], 401);
        }

        // Une connexion réussie remet à zéro le compteur de cet identifiant (celui de l'adresse reste).
        $this->loginThrottle->forgetIdentifier($email);

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

    /** « Trop de tentatives. Réessayez dans N minute(s). » : durée arrondie à la minute supérieure (jamais « 0 minute »). */
    private function tooManyAttempts(int $seconds): JsonResponse
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return new JsonResponse(
            ['error' => sprintf('Trop de tentatives. Réessayez dans %d minute%s.', $minutes, $minutes === 1 ? '' : 's'), 'retryAfterSeconds' => $seconds],
            429,
            ['Retry-After' => (string) $seconds],
        );
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
