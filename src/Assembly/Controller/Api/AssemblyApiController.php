<?php

declare(strict_types=1);

namespace App\Assembly\Controller\Api;

use App\Account\Entity\UserRole;
use App\Assembly\Service\AssemblyService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * API des comptes rendus d'AG (#361), administrateurs uniquement. Garde de rôle ici ET dans le service (défense en profondeur).
 * Les erreurs métier (422 champ par champ, 409 déjà validé ou modifié, 404) sont traduites par ExceptionTranslator. Un identifiant
 * mal formé est refusé comme un accès interdit.
 */
final class AssemblyApiController
{
    public function __construct(
        private readonly AssemblyService $assemblies,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $id = $this->assemblies->create($admin, $request->allBody());

        return new JsonResponse(['id' => $id, 'url' => '/admin/assemblies/' . $id], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $revision = $this->assemblies->saveDraft($admin, StrictId::orDenied($id), $this->revision($request), $request->allBody());

        return new JsonResponse(['revision' => $revision]);
    }

    public function validate(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->assemblies->validate($admin, StrictId::orDenied($id), $this->revision($request));

        return new JsonResponse(['validated' => true]);
    }

    public function correct(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $reason = $request->body('reason');
        $newId = $this->assemblies->correct($admin, StrictId::orDenied($id), is_string($reason) ? $reason : '');

        return new JsonResponse(['id' => $newId, 'url' => '/admin/assemblies/' . $newId], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->assemblies->deleteDraft($admin, StrictId::orDenied($id));

        return new JsonResponse([], 204);
    }

    /** Révision lue par la page ; absente ou invalide : -1, jamais égale à une révision réelle, donc 409. */
    private function revision(Request $request): int
    {
        $value = $request->body('revision');

        return is_int($value) && $value >= 0 ? $value : -1;
    }
}
