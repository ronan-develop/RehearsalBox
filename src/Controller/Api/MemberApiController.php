<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\MemberSearchService;
use App\Support\StrictId;

/**
 * Liste proposée après « @ » (#178). Contexte obligatoire, vérifié par le service : `conversation` (participant) ou
 * `groupId` + `targetGroupId` (page de nouvelle conversation). Renvoie des noms et des groupes, jamais d'adresse.
 */
final class MemberApiController
{
    public function __construct(
        private readonly MemberSearchService $search,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function search(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $query = $request->query('q', '');
        $query = is_string($query) ? $query : '';

        $conversation = $request->query('conversation');
        $members = $conversation !== null
            ? $this->search->search($user->id(), $query, $this->idOrDenied($conversation))
            : $this->search->searchForNewConversation(
                $user->id(),
                $query,
                $this->idOrDenied($request->query('groupId')),
                $this->idOrDenied($request->query('targetGroupId')),
            );

        return new JsonResponse(['members' => $members]);
    }

    private function idOrDenied(mixed $value): int
    {
        return StrictId::from($value) ?? throw new AccessDeniedException('Accès refusé.');
    }
}
