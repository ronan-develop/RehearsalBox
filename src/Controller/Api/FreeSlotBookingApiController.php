<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Enum\UserRole;
use App\Entity\FreeSlotBooking;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Entity\BookingPlan;
use App\Entity\PlanConflict;
use App\Service\BookingPlanner;
use App\Service\Exception\AvailabilityValidationException;
use App\Service\FreeSlotBookingService;
use App\Support\StrictId;

/**
 * Réservations libres du local (#263). L'identité vient UNIQUEMENT de la session ; un identifiant mal formé est refusé comme
 * un accès interdit (même réponse que pour une réservation inexistante). Les règles métier sont dans FreeSlotBookingService, les
 * exceptions sont traduites par le Kernel (ExceptionTranslator) ; ici, seulement lire la requête et choisir le rôle exigé.
 */
final class FreeSlotBookingApiController
{
    public function __construct(
        private readonly FreeSlotBookingService $bookings,
        private readonly BookingPlanner $planner,
        private readonly AuthGuard $authGuard,
    ) {
    }

    /** Réserve une plage libre pour un groupe dont la personne est membre. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $booking = $this->bookings->request(
            $user->id(),
            StrictId::orDenied($request->body('groupId')),
            $this->dateOrFail($request->body('bookingDate')),
            $this->textOrNull($request->body('startTime')),
            $this->textOrNull($request->body('endTime')),
            $this->textOrNull($request->body('reason')),
        );

        return new JsonResponse(['booking' => self::toArray($booking)], 201);
    }

    /**
     * Plan d'une réservation voulue (`?groupId=&bookingDate=&startTime=&endTime=`) : les parties libres à réserver et ce qui chevauche
     * d'autres groupes (avec le créneau visé, pour y adresser une demande). Lecture seule : rien n'est créé.
     */
    public function plan(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $plan = $this->planner->planFor(
            $user->id(),
            StrictId::orDenied($request->query('groupId')),
            $this->dateOrFail($request->query('bookingDate')),
            $this->textOrNull($request->query('startTime')),
            $this->textOrNull($request->query('endTime')),
        );

        return new JsonResponse(['plan' => self::planToArray($plan)]);
    }

    /** Annule une réservation de son groupe qui n'a pas commencé. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->bookings->cancel($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    /** Les réservations à venir d'un groupe (`?groupId=`), pour ses membres. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $list = $this->bookings->forGroup($user->id(), StrictId::orDenied($request->query('groupId')));

        return new JsonResponse(['bookings' => array_map(self::toArray(...), $list)]);
    }

    /** À valider (administrateurs). */
    public function pending(Request $request): JsonResponse
    {
        $this->authGuard->requireRole(UserRole::Admin);

        return new JsonResponse(['bookings' => array_map(self::toArray(...), $this->bookings->pending())]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new JsonResponse(['booking' => self::toArray($this->bookings->approve($admin->id(), StrictId::orDenied($id)))]);
    }

    /** Refuse, avec un motif facultatif (`note`). */
    public function refuse(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $booking = $this->bookings->refuse($admin->id(), StrictId::orDenied($id), $this->textOrNull($request->body('note')));

        return new JsonResponse(['booking' => self::toArray($booking)]);
    }

    private function dateOrFail(mixed $value): \DateTimeImmutable
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new AvailabilityValidationException(['bookingDate' => 'Date invalide (format AAAA-MM-JJ).']);
        }

        return $date;
    }

    private function textOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function planToArray(BookingPlan $plan): array
    {
        $hhmm = static fn (string $time): string => substr($time, 0, 5);

        return [
            'fullyFree' => $plan->isFullyFree(),
            'freeParts' => array_map(static fn ($range): array => ['startTime' => $hhmm($range->start()), 'endTime' => $hhmm($range->end())], $plan->freeParts()),
            'conflicts' => array_map(static fn (PlanConflict $conflict): array => [
                'kind' => $conflict->kind(),
                'slotId' => $conflict->slotId(),
                'groupName' => $conflict->groupName(),
                'own' => $conflict->isOwn(),
                'startTime' => $hhmm($conflict->overlap()->start()),
                'endTime' => $hhmm($conflict->overlap()->end()),
            ], $plan->conflicts()),
        ];
    }

    /** @return array<string, mixed> */
    private static function toArray(FreeSlotBooking $booking): array
    {
        return [
            'id' => $booking->id(),
            'groupId' => $booking->requester()->groupId(),
            'bookingDate' => $booking->date()->format('Y-m-d'),
            'startTime' => substr($booking->range()->start(), 0, 5),
            'endTime' => substr($booking->range()->end(), 0, 5),
            'status' => $booking->status()->value,
            'reason' => $booking->reason(),
            'decisionNote' => $booking->decisionNote(),
        ];
    }
}
