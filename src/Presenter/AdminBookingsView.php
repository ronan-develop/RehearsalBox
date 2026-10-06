<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\FreeSlotBooking;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Support\FrenchDate;

/** Réservations à valider, prêtes à afficher (#263) : nom du groupe, jour et plage lisibles ; aucun texte n'est échappé ici (le gabarit le fait). */
final class AdminBookingsView
{
    public function __construct(private readonly GroupRepositoryInterface $groups)
    {
    }

    /**
     * @param list<FreeSlotBooking> $bookings
     *
     * @return list<array{id: int, groupName: string, when: string, range: string, reason: ?string}>
     */
    public function items(array $bookings): array
    {
        return array_map(fn (FreeSlotBooking $booking): array => [
            'id' => $booking->id(),
            'groupName' => $this->groups->findById($booking->requester()->groupId())?->name() ?? 'Groupe supprimé',
            'when' => FrenchDate::long($booking->date()),
            'range' => substr($booking->range()->start(), 0, 5) . ' – ' . substr($booking->range()->end(), 0, 5),
            'reason' => $booking->reason(),
        ], $bookings);
    }
}
