<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationTrashService;
use App\Support\StrictId;

/**
 * Corbeille des conversations et avis (#190). L'identité vient UNIQUEMENT de la session ; un identifiant mal formé est
 * refusé comme un accès interdit. Les droits (seul l'initiateur) sont dans ConversationTrashService.
 */
final class ConversationTrashApiController
{
    public function __construct(
        private readonly ConversationTrashService $trash,
        private readonly AuthGuard $authGuard,
    ) {
    }

    /** Met la conversation à la corbeille (initiateur seulement). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trash->delete($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trash->restore($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    /** Suppression définitive d'une conversation déjà à la corbeille. */
    public function destroyPermanently(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trash->deletePermanently($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    /** Ferme un avis de la personne connectée (celui d'un autre est ignoré). */
    public function dismissAlert(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trash->dismissAlert($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }
}
