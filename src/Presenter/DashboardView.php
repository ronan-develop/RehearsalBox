<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Entity\DashboardExceptionItem;
use App\Entity\DashboardRequestItem;
use App\Entity\Enum\ExceptionDirection;
use App\Group\Entity\Group;
use App\Entity\RecurringSlot;
use App\Entity\SlotException;
use App\Entity\User;
use App\Group\Repository\GroupRepositoryInterface;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Support\Initials;

/**
 * Données du tableau de bord (#239) : planning, créneaux exceptionnels et bloc « Demandes de créneau » (échanges entre groupes et
 * réservations libres, rangés en reçues / envoyées / archivées, plus récentes d'abord). Construit à partir des services ; le gabarit
 * PHP ne fait que dessiner. Chaque groupe demandeur n'est lu qu'une fois (les groupes de l'utilisateur sont déjà connus).
 */
final class DashboardView
{
    public function __construct(
        private readonly AvailabilityServiceInterface $availability,
        private readonly GroupRepositoryInterface $groups,
        private readonly SlotServiceInterface $slots,
        private readonly PlanningView $planning,
        private readonly DashboardBookings $bookings,
    ) {
    }

    /** @return array<string, mixed> les variables du gabarit `dashboard/index` (hors jeton CSRF) */
    public function for(User $user): array
    {
        $groups = $this->groups->findByMember($user->id());

        $groupRoles = [];
        $groupsById = [];
        foreach ($groups as $group) {
            $groupRoles[$group->id()] = $this->groups->roleOf($group->id(), $user->id());
            $groupsById[$group->id()] = $group;
        }

        $slotsById = [];
        foreach ($this->slots->findAllActive() as $slot) {
            $slotsById[$slot->id()] = $slot;
        }

        // Indexés par id d'exception : une demande visible depuis plusieurs groupes de l'utilisateur n'apparaît qu'une fois.
        $received = [];
        $sent = [];
        $archived = [];
        foreach ($groups as $group) {
            foreach ($this->availability->findPendingForHolderGroup($group->id(), $user->id()) as $exception) {
                $received[$exception->id()] ??= $this->toItem($exception, ExceptionDirection::Recue, $slotsById, $groupsById);
            }
            foreach ($this->availability->findByRequestingGroup($group->id(), $user->id()) as $exception) {
                $sent[$exception->id()] ??= $this->toItem($exception, ExceptionDirection::Envoyee, $slotsById, $groupsById);
            }
            foreach ($this->availability->findArchivedForGroup($group->id(), $user->id()) as $exception) {
                $direction = $exception->requester()->groupId() === $group->id() ? ExceptionDirection::Envoyee : ExceptionDirection::Recue;
                $archived[$exception->id()] ??= $this->toItem($exception, $direction, $slotsById, $groupsById);
            }
        }

        // Réservations libres : « envoyées » ou « archivées », jamais « reçues » (seuls les admins les valident).
        $bookingItems = $this->bookings->forGroups($groups, $user->id());

        // Limite connue : avec plusieurs groupes, le premier (ordre alphabétique) est affiché dans l'en-tête ; pas de « groupe principal » en base.
        $primaryGroup = $groups[0] ?? null;

        return [
            'planningDays' => $this->planning->fixedDays(),
            'exceptionalPlanningSlots' => $this->slots->findOccasionalPlanningSlots(),
            'receivedExceptions' => self::newestFirst($received),
            'sentExceptions' => self::newestFirst([...$sent, ...$bookingItems['sent']]),
            'archivedExceptions' => self::newestFirst([...$archived, ...$bookingItems['archived']]),
            'currentUserRole' => $user->role(),
            'currentUserGroupRoles' => $groupRoles,
            'currentUserGroupName' => $primaryGroup?->name(),
            'currentUserInitials' => Initials::from($user->displayName()),
        ];
    }

    /**
     * Plus récent d'abord ; à created_at égal (même seconde), l'id le plus grand (créé en dernier) passe en premier, pour un ordre déterministe.
     *
     * @param array<array-key, DashboardRequestItem> $items
     *
     * @return list<DashboardRequestItem>
     */
    private static function newestFirst(array $items): array
    {
        $items = array_values($items);
        usort($items, static fn (DashboardRequestItem $a, DashboardRequestItem $b): int => [$b->createdAt(), $b->kind()->value, $b->requestId()] <=> [$a->createdAt(), $a->kind()->value, $a->requestId()]);

        return $items;
    }

    /**
     * @param array<int, RecurringSlot> $slotsById
     * @param array<int, Group>         $groupsById groupes déjà lus, complété à chaque nouveau groupe demandeur ou titulaire
     */
    private function toItem(SlotException $exception, ExceptionDirection $direction, array $slotsById, array &$groupsById): DashboardExceptionItem
    {
        $requestingGroup = $this->groupById($exception->requester()->groupId(), $groupsById);
        $holderSlot = $slotsById[$exception->recurringSlotId()] ?? null;
        $holderGroup = $holderSlot === null ? null : $this->groupById($holderSlot->groupId(), $groupsById);

        return new DashboardExceptionItem(
            $exception,
            $direction,
            $requestingGroup->name(),
            $requestingGroup->colorHex(),
            // Plage demandée (#263) : la carte montre ce que le titulaire accorde, pas tout son créneau.
            $holderSlot?->within($exception->range()),
            $holderGroup?->name(),
        );
    }

    /** @param array<int, Group> $groupsById */
    private function groupById(int $id, array &$groupsById): Group
    {
        if (!isset($groupsById[$id])) {
            $group = $this->groups->findById($id);
            \assert($group !== null);
            $groupsById[$id] = $group;
        }

        return $groupsById[$id];
    }
}
