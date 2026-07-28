<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Group;
use App\Entity\LineupMember;
use App\Entity\UpcomingShow;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\GroupServiceInterface;

final class GroupSpaceApiController
{
    public function __construct(
        private readonly GroupServiceInterface $groupService,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $groupId = (int) $id;

        if (!$this->groupRepository->isMember($groupId, $user->id())) {
            return new JsonResponse(['error' => "Vous n'appartenez pas à ce groupe."], 403);
        }

        $group = $this->groupRepository->findById($groupId);
        if ($group === null) {
            return new JsonResponse(['error' => 'Groupe introuvable.'], 404);
        }

        return new JsonResponse(self::toArray($group));
    }

    public function updateProfile(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $groupId = (int) $id;

        $lineup = array_map(
            static fn (array $m): LineupMember => LineupMember::fromArray(['name' => (string) $m['name'], 'instrument' => (string) $m['instrument']]),
            (array) $request->body('lineup', []),
        );
        $upcomingShows = array_map(
            static fn (array $s): UpcomingShow => UpcomingShow::fromArray(['date' => (string) $s['date'], 'venue' => (string) $s['venue']]),
            (array) $request->body('upcomingShows', []),
        );

        try {
            $group = $this->groupService->updateProfile($groupId, $lineup, $upcomingShows, $user->id());
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse(self::toArray($group));
    }

    /** @return array<string, mixed> */
    private static function toArray(Group $group): array
    {
        return [
            'id' => $group->id(),
            'name' => $group->name(),
            'genre' => $group->genre(),
            'colorHex' => $group->colorHex(),
            'lineup' => array_map(static fn (LineupMember $m): array => $m->toArray(), $group->lineup()),
            'upcomingShows' => array_map(static fn (UpcomingShow $s): array => $s->toArray(), $group->upcomingShows()),
        ];
    }
}
