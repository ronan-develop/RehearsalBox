<?php

declare(strict_types=1);

namespace App\Planning\Controller\Api;

use App\Account\Entity\UserRole;
use App\Planning\Entity\Weekday;
use App\Planning\Entity\RecurringSlot;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Planning\Service\SlotServiceInterface;
use App\Planning\Exception\OverlappingSlotException;

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
        // Jour absent ou invalide : refusé (jamais converti en lundi par défaut, jamais une erreur PHP brute).
        $weekday = $this->weekdayOrNull($request->body('weekday'));
        if ($weekday === null) {
            return new JsonResponse(['error' => 'Jour de la semaine invalide.'], 422);
        }
        $startTime = (string) $request->body('startTime', '');
        $endTime = (string) $request->body('endTime', '');

        try {
            $slot = $this->slotService->create($groupId, $weekday, $startTime, $endTime);
        } catch (\InvalidArgumentException|OverlappingSlotException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(self::toArray($slot), 201);
    }

    /** Entier ou chaîne de chiffres de 0 (lundi) à 6 (dimanche) ; tout le reste (absent, texte, décimal, tableau) donne null. */
    private function weekdayOrNull(mixed $value): ?Weekday
    {
        if (is_string($value) && preg_match('/^[0-9]$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) ? Weekday::tryFrom($value) : null;
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        $startTime = (string) $request->body('startTime', '');
        $endTime = (string) $request->body('endTime', '');

        try {
            $slot = $this->slotService->update((int) $id, $startTime, $endTime);
        } catch (\InvalidArgumentException|OverlappingSlotException $e) {
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

    /** @return array<string, mixed> */
}
