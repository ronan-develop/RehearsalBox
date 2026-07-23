<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\RecurringSlot;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\SlotServiceInterface;
use App\Service\Exception\OverlappingSlotException;

final class SlotApiController
{
    public function __construct(
        private readonly SlotServiceInterface $slotService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        return new JsonResponse(['slots' => array_map(self::toArray(...), $this->slotService->findAllActive())]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $groupId = (int) $request->body('groupId', 0);
        $weekday = Weekday::from((int) $request->body('weekday', 0));
        $startTime = (string) $request->body('startTime', '');
        $endTime = (string) $request->body('endTime', '');

        try {
            $slot = $this->slotService->create($groupId, $weekday, $startTime, $endTime);
        } catch (\InvalidArgumentException|OverlappingSlotException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(self::toArray($slot), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $startTime = (string) $request->body('startTime', '');
        $endTime = (string) $request->body('endTime', '');

        try {
            $slot = $this->slotService->update((int) $id, $startTime, $endTime);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(self::toArray($slot));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $this->slotService->delete((int) $id);

        return new JsonResponse([], 204);
    }

    /** @return array<string, mixed> */
    private static function toArray(RecurringSlot $slot): array
    {
        return [
            'id' => $slot->id(),
            'groupId' => $slot->groupId(),
            'weekday' => $slot->weekday()->value,
            'startTime' => $slot->startTime(),
            'endTime' => $slot->endTime(),
            'isActive' => $slot->isActive(),
        ];
    }
}
