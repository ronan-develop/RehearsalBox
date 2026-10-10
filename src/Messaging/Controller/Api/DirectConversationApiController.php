<?php

declare(strict_types=1);

namespace App\Messaging\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Messaging\Service\Direct\DirectConversationService;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * Message direct (#269) : POST /api/conversations/direct. L'émetteur vient UNIQUEMENT de la session ; la personne visée est
 * un identifiant validé côté serveur (mal formé, soi-même, inconnue ou inactive : même refus). Contrôleur mince.
 * Répondre, éditer, citer, supprimer passent par les routes de conversation existantes.
 */
final class DirectConversationApiController
{
    public function __construct(
        private readonly DirectConversationService $direct,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $targetId = StrictId::orDenied($request->body('targetUserId'));
        $message = $request->body('message');

        $conversation = $this->direct->start($user->id(), $targetId, is_string($message) ? $message : '');

        return new JsonResponse(['id' => $conversation->id()], 201);
    }
}
