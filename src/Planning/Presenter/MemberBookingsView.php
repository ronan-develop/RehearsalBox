<?php

declare(strict_types=1);

namespace App\Planning\Presenter;

use App\Planning\Entity\FreeSlotBookingStatus;
use App\Planning\Entity\FreeSlotBooking;
use App\Support\FrenchDate;

/** Les réservations d'un groupe, prêtes à afficher à ses membres (#263) : jour et plage lisibles, état en clair ; le gabarit échappe. */
final class MemberBookingsView
{
    /**
     * @param list<FreeSlotBooking> $bookings
     *
     * @return list<array{id: int, when: string, range: string, status: string, statusLabel: string, reason: ?string, decisionNote: ?string, cancellable: bool}>
     */
    public function items(array $bookings): array
    {
        return array_map(static fn (FreeSlotBooking $booking): array => [
            'id' => $booking->id(),
            'when' => FrenchDate::long($booking->date()),
            'range' => substr($booking->range()->start(), 0, 5) . ' – ' . substr($booking->range()->end(), 0, 5),
            'status' => $booking->status()->value,
            'statusLabel' => match ($booking->status()) {
                FreeSlotBookingStatus::EnAttente => 'En attente de validation',
                FreeSlotBookingStatus::Validee => 'Validée',
                FreeSlotBookingStatus::Refusee => 'Refusée',
                FreeSlotBookingStatus::Annulee => 'Annulée',
            },
            'reason' => $booking->reason(),
            'decisionNote' => $booking->decisionNote(),
            // Une réservation encore en attente ou validée peut être annulée (le serveur refuse si elle a commencé).
            'cancellable' => $booking->occupies(),
        ], $bookings);
    }
}
