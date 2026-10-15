<?php

declare(strict_types=1);

namespace App\Association\Controller\Api;

use App\Account\Entity\UserRole;
use App\Association\Service\AssociationTextService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * API des textes de l'association (#472), administrateurs uniquement. Garde de rôle ici ET dans le service. Les erreurs métier
 * (422 par champ, 409 modifié entre-temps, 404) sont traduites par ExceptionTranslator ; un identifiant mal formé est refusé
 * comme un accès interdit.
 */
final class AssociationTextApiController
{
    public function __construct(
        private readonly AssociationTextService $texts,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $id = $this->texts->create($admin, $request->allBody());

        return new JsonResponse(['id' => $id, 'url' => '/admin/texts/' . $id], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $revision = $this->texts->update($admin, StrictId::orDenied($id), $this->revision($request), $request->allBody());

        return new JsonResponse(['revision' => $revision]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->texts->delete($admin, StrictId::orDenied($id));

        return new JsonResponse([], 204);
    }

    /** Révision lue par la page ; absente ou invalide : -1, jamais égale à une révision réelle, donc 409. */
    private function revision(Request $request): int
    {
        $value = $request->body('revision');

        return is_int($value) && $value >= 0 ? $value : -1;
    }
}
