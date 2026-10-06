<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SlotException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\AvailabilityServiceInterface;

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

    public function respond(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $accepted = (bool) $request->body('accepted', false);

        $responded = $this->availabilityService->respond((int) $exceptionId, $accepted, $user->id());

        return new JsonResponse(self::toArray($responded));
    }

    public function update(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $occurrenceDate = self::parseDate($request->body('occurrenceDate'));
        if ($occurrenceDate === null) {
            return new JsonResponse(['error' => 'Date invalide (format AAAA-MM-JJ attendu).'], 422);
        }
        $reason = $request->body('reason') !== null ? (string) $request->body('reason') : null;

        $updated = $this->availabilityService->updateRequest((int) $exceptionId, $occurrenceDate, $reason, $user->id());

        return new JsonResponse(self::toArray($updated));
    }

    public function destroy(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $this->availabilityService->cancelRequest((int) $exceptionId, $user->id());

        return new JsonResponse([], 204);
    }

    /** Date stricte AAAA-MM-JJ : rejette le vide, les types inattendus et les dates impossibles (30 février). */
    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
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
