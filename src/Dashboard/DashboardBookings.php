<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Dashboard\DashboardBookingItem;
use App\Planning\Entity\FreeSlotBookingStatus;
use App\Group\Entity\Group;
use App\Planning\Service\FreeSlotBookingService;

/**
 * Les réservations libres des groupes d'un utilisateur, rangées comme les échanges du bloc « Demandes de créneau » (#292) :
 * « envoyées » tant qu'elles attendent un administrateur, « archivées » dès qu'elles ont une réponse ou sont annulées.
 * Jamais « reçues » : seuls les administrateurs valident une réservation libre.
 */
final class DashboardBookings
{
    public function __construct(private readonly FreeSlotBookingService $bookings)
    {
    }

    /**
     * @param list<Group> $groups les groupes dont l'utilisateur est membre
     *
     * @return array{sent: list<DashboardBookingItem>, archived: list<DashboardBookingItem>}
     */
    public function forGroups(array $groups, int $userId): array
    {
        $sent = [];
        $archived = [];
        foreach ($groups as $group) {
            foreach ($this->bookings->forGroup($userId, $group->id()) as $booking) {
                $item = new DashboardBookingItem($booking, $group->name(), $group->colorHex());
                if ($booking->status() === FreeSlotBookingStatus::EnAttente) {
                    $sent[] = $item;
                } else {
                    $archived[] = $item;
                }
            }
        }

        return ['sent' => $sent, 'archived' => $archived];
    }
}
