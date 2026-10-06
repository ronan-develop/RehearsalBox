<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\ConversationMuteService;
use App\Support\StrictId;

/**
 * Sourdine d'une conversation (#210). L'identité vient UNIQUEMENT de la session ; un identifiant mal formé est refusé
 * comme un accès interdit. Les droits (participant) sont dans ConversationMuteService.
 */
final class ConversationMuteApiController
{
    public function __construct(
        private readonly ConversationMuteService $mutes,
        private readonly AuthGuard $authGuard,
    ) {
    }

    /** Met la conversation en sourdine (idempotent). */
    public function mute(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->mutes->mute($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok', 'muted' => true]);
    }

    /** Rétablit les notifications (idempotent). */
    public function unmute(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->mutes->unmute($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok', 'muted' => false]);
    }
}
