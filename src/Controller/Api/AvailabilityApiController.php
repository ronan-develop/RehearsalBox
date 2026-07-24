<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\RequestableSlot;
use App\Entity\SlotException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Exception\RequestAlreadyRespondedException;

final class AvailabilityApiController
{
    public function __construct(
        private readonly AvailabilityServiceInterface $availabilityService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function pendingForGroup(Request $request, string $groupId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $exceptions = $this->availabilityService->findPendingForHolderGroup((int) $groupId, $user->id());

        return new JsonResponse(['exceptions' => array_map(self::toArray(...), $exceptions)]);
    }

    public function requestedByGroup(Request $request, string $groupId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $exceptions = $this->availabilityService->findByRequestingGroup((int) $groupId, $user->id());

        return new JsonResponse(['exceptions' => array_map(self::toArray(...), $exceptions)]);
    }

    public function requestableSlots(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $slots = $this->availabilityService->findRequestableSlotsFor($user->id());

        return new JsonResponse(['slots' => array_map(self::slotToArray(...), $slots)]);
    }

    public function request(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $recurringSlotId = (int) $request->body('recurringSlotId', 0);
        $occurrenceDate = new \DateTimeImmutable((string) $request->body('occurrenceDate', ''));
        $requestingGroupId = (int) $request->body('requestingGroupId', 0);
        $reason = $request->body('reason') !== null ? (string) $request->body('reason') : null;

        $created = $this->availabilityService->request($recurringSlotId, $occurrenceDate, $requestingGroupId, $user->id(), $reason);

        return new JsonResponse(self::toArray($created), 201);
    }

    public function respond(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $accepted = (bool) $request->body('accepted', false);

        try {
            $responded = $this->availabilityService->respond((int) $exceptionId, $accepted, $user->id());
        } catch (RequestAlreadyRespondedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        return new JsonResponse(self::toArray($responded));
    }

    /** @return array<string, mixed> */
    private static function slotToArray(RequestableSlot $requestableSlot): array
    {
        $slot = $requestableSlot->slot();

        return [
            'id' => $slot->id(),
            'groupId' => $slot->groupId(),
            'groupName' => $requestableSlot->groupName(),
            'weekday' => $slot->weekday()->value,
            'startTime' => $slot->startTime(),
            'endTime' => $slot->endTime(),
        ];
    }

    /** @return array<string, mixed> */
    private static function toArray(SlotException $exception): array
    {
        return [
            'id' => $exception->id(),
            'recurringSlotId' => $exception->recurringSlotId(),
            'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d'),
            'status' => $exception->status()->value,
            'requestedByGroupId' => $exception->requestedByGroupId(),
            'requestReason' => $exception->requestReason(),
            'respondedByUserId' => $exception->respondedByUserId(),
        ];
    }
}
