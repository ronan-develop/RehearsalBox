<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\GroupContactService;

final class GroupContactApiController
{
    public function __construct(
        private readonly GroupContactService $groupContactService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $message = trim((string) $request->body('message', ''));
        if ($message === '') {
            return new JsonResponse(['error' => 'Le message est requis.'], 422);
        }

        try {
            $this->groupContactService->send((int) $id, $user->id(), $user->email(), $message);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Groupe introuvable.'], 404);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
