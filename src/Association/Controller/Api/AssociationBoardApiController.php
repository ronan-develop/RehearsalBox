<?php

declare(strict_types=1);

namespace App\Association\Controller\Api;

use App\Account\Entity\UserRole;
use App\Association\Service\AssociationBoardService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;

/**
 * API du bureau de l'association (#478), administrateurs uniquement (garde de rôle ici ET dans le service). Le poste est la valeur
 * de l'adresse : un poste inconnu donne 404, une saisie refusée 422 par champ (ExceptionTranslator).
 */
final class AssociationBoardApiController
{
    public function __construct(
        private readonly AssociationBoardService $board,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function assign(Request $request, string $post): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->board->assign($admin, $post, $request->allBody());

        return new JsonResponse([]);
    }

    public function vacate(Request $request, string $post): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->board->vacate($admin, $post);

        return new JsonResponse([], 204);
    }
}
