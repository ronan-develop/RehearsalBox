<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\SlotException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Support\StrictId;
use App\Service\Exception\AvailabilityValidationException;

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

        $exceptions = $this->availabilityService->findPendingForHolderGroup(StrictId::orDenied($groupId), $user->id());

        return new JsonResponse(['exceptions' => array_map(self::toArray(...), $exceptions)]);
    }

    public function requestedByGroup(Request $request, string $groupId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $exceptions = $this->availabilityService->findByRequestingGroup(StrictId::orDenied($groupId), $user->id());

        return new JsonResponse(['exceptions' => array_map(self::toArray(...), $exceptions)]);
    }

    /**
     * Répond à une demande. Le corps porte la réponse (`accepted`, booléen strict : « false » refuse, il n'accepte jamais) ET la
     * date que le titulaire a vue (`occurrenceDate`) : si le groupe demandeur l'a changée entre-temps, l'acceptation est refusée
     * (409) et la demande reste en attente.
     */
    public function respond(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $accepted = match ($request->body('accepted')) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => null,
        };
        $seenDate = self::parseDate($request->body('occurrenceDate'));

        $errors = [];
        if ($accepted === null) {
            $errors['accepted'] = 'Indiquez si la demande est acceptée ou refusée.';
        }
        if ($seenDate === null) {
            $errors['occurrenceDate'] = 'La date de la demande est requise (format AAAA-MM-JJ).';
        }
        if ($errors !== []) {
            throw new AvailabilityValidationException($errors);
        }

        $responded = $this->availabilityService->respond(StrictId::orDenied($exceptionId), $accepted, $user->id(), $seenDate);

        return new JsonResponse(self::toArray($responded));
    }

    public function update(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $occurrenceDate = self::parseDate($request->body('occurrenceDate'));
        if ($occurrenceDate === null) {
            throw new AvailabilityValidationException(['occurrenceDate' => 'Date invalide (format AAAA-MM-JJ attendu).']);
        }
        $rawReason = $request->body('reason');
        if ($rawReason !== null && !is_string($rawReason)) {
            throw new AvailabilityValidationException(['reason' => 'Le motif doit être du texte.']);
        }

        $updated = $this->availabilityService->updateRequest(StrictId::orDenied($exceptionId), $occurrenceDate, $rawReason, $user->id());

        return new JsonResponse(self::toArray($updated));
    }

    public function destroy(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $this->availabilityService->cancelRequest(StrictId::orDenied($exceptionId), $user->id());

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
            'requestedByGroupId' => $exception->requester()->groupId(),
            'requestReason' => $exception->requestReason(),
            'respondedByUserId' => $exception->respondedByUserId(),
        ];
    }
}
