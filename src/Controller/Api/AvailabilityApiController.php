<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SlotException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Exception\SlotAlreadyClaimedException;

final class AvailabilityApiController
{
    public function __construct(
        private readonly AvailabilityServiceInterface $availabilityService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function weekView(Request $request): JsonResponse
    {
        $this->authGuard->requireLogin();

        $from = new \DateTimeImmutable((string) $request->query('from', 'today'));
        $to = new \DateTimeImmutable((string) $request->query('to', '+7 days'));

        $exceptions = $this->availabilityService->findLiberatedBetween($from, $to);

        return new JsonResponse(['exceptions' => array_map(self::toArray(...), $exceptions)]);
    }

    public function claim(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $groupId = (int) $request->body('groupId', 0);

        try {
            $claimed = $this->availabilityService->claim((int) $exceptionId, $groupId, $user->id());
        } catch (SlotAlreadyClaimedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        return new JsonResponse(self::toArray($claimed));
    }

    /** @return array<string, mixed> */
    private static function toArray(SlotException $exception): array
    {
        return [
            'id' => $exception->id(),
            'recurringSlotId' => $exception->recurringSlotId(),
            'occurrenceDate' => $exception->occurrenceDate()->format('Y-m-d'),
            'status' => $exception->status()->value,
            'releasedReason' => $exception->releasedReason(),
            'claimedByGroupId' => $exception->claimedByGroupId(),
        ];
    }
}
