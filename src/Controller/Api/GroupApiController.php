<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\GroupServiceInterface;

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

        $name = (string) $request->body('name', '');
        $genre = $request->body('genre') !== null ? (string) $request->body('genre') : null;
        $colorHex = $request->body('colorHex') !== null ? (string) $request->body('colorHex') : null;

        $group = $this->groupService->create($name, $genre, $colorHex);

        return new JsonResponse(self::toArray($group), 201);
    }

    public function addMember(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $email = (string) $request->body('email', '');

        try {
            $this->groupService->addMemberByEmail((int) $id, $email);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    public function removeMember(Request $request, string $id, string $userId): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $this->groupService->removeMember((int) $id, (int) $userId);

        return new JsonResponse([], 204);
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
