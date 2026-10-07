<?php

declare(strict_types=1);

namespace App\Group\Controller;

use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Group\Exception\GroupValidationException;
use App\Support\StrictId;
use App\Group\Service\GroupServiceInterface;

final class GroupApiController
{
    public function __construct(
        private readonly GroupServiceInterface $groupService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        return new JsonResponse(['groups' => array_map(self::toArray(...), $this->groupService->findAll())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $group = $this->groupService->create(
            $this->text($request, 'name'),
            $this->optionalText($request, 'genre'),
            $this->optionalText($request, 'colorHex'),
            $this->text($request, 'contactEmail'),
        );

        return new JsonResponse(self::toArray($group), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);
        $groupId = StrictId::orDenied($id);

        try {
            $group = $this->groupService->update(
                $groupId,
                $this->text($request, 'name'),
                $this->optionalText($request, 'genre'),
                $this->optionalText($request, 'colorHex'),
                $this->text($request, 'contactEmail'),
            );
        } catch (GroupValidationException $e) {
            throw $e; // une erreur de saisie : traduite en 422 par le Kernel, avant le repli « groupe introuvable » ci-dessous
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(self::toArray($group));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $this->groupService->delete(StrictId::orDenied($id));

        return new JsonResponse([], 204);
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $email = (string) $request->body('email', '');

        try {
            $this->groupService->addMemberByEmail(StrictId::orDenied($id), $email);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    public function removeMember(Request $request, string $id, string $userId): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $this->groupService->removeMember(StrictId::orDenied($id), StrictId::orDenied($userId));

        return new JsonResponse([], 204);
    }

    /** Champ texte obligatoire : une valeur absente ou qui n'est pas du texte devient une chaîne vide (refusée par la règle de saisie). */
    private function text(Request $request, string $key): string
    {
        $value = $request->body($key);

        return is_string($value) ? $value : '';
    }

    /** Champ texte facultatif : absent ou null = aucun ; autre chose que du texte (tableau, nombre…) est une erreur de saisie. */
    private function optionalText(Request $request, string $key): ?string
    {
        $value = $request->body($key);
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new GroupValidationException([$key => 'Ce champ doit être du texte.']);
    }

    /** @return array<string, mixed> */
    private static function toArray(Group $group): array
    {
        return [
            'id' => $group->id(),
            'name' => $group->name(),
            'genre' => $group->genre(),
            'colorHex' => $group->colorHex(),
        ];
    }
}
