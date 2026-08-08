<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DashboardExceptionItem;
use App\Entity\Enum\ExceptionDirection;
use App\Entity\Enum\UserRole;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Contract\GroupServiceInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Support\Initials;
use App\View\TemplateRendererInterface;

final class PageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AvailabilityServiceInterface $availabilityService,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SlotServiceInterface $slotService,
        private readonly GroupServiceInterface $groupService,
        private readonly GroupDocumentRepositoryInterface $groupDocumentRepository,
    ) {
    }

    public function login(): Response
    {
        return new Response($this->renderer->render('auth/login', ['csrfToken' => $this->csrfTokenManager->getToken()]));
    }

    public function register(): Response
    {
        return new Response($this->renderer->render('auth/register', ['csrfToken' => $this->csrfTokenManager->getToken()]));
    }

    public function dashboard(): Response
    {
        $user = $this->authGuard->requireLogin();
        $groups = $this->groupRepository->findByMember($user->id());

        $groupRoles = [];
        foreach ($groups as $group) {
            $groupRoles[$group->id()] = $this->groupRepository->roleOf($group->id(), $user->id());
        }

        $slotsById = [];
        foreach ($this->slotService->findAllActive() as $slot) {
            $slotsById[$slot->id()] = $slot;
        }

        $receivedItems = [];
        $sentItems = [];
        $archivedItems = [];
        $seenPendingIds = [];
        $seenRequestedIds = [];
        $seenArchivedIds = [];
        foreach ($groups as $group) {
            foreach ($this->availabilityService->findPendingForHolderGroup($group->id(), $user->id()) as $exception) {
                if (isset($seenPendingIds[$exception->id()])) {
                    continue;
                }
                $seenPendingIds[$exception->id()] = true;
                $requestingGroup = $this->groupRepository->findById($exception->requestedByGroupId());
                \assert($requestingGroup !== null);
                $receivedItems[] = new DashboardExceptionItem($exception, ExceptionDirection::Recue, $requestingGroup->name(), $requestingGroup->colorHex(), $slotsById[$exception->recurringSlotId()] ?? null);
            }
            foreach ($this->availabilityService->findByRequestingGroup($group->id(), $user->id()) as $exception) {
                if (isset($seenRequestedIds[$exception->id()])) {
                    continue;
                }
                $seenRequestedIds[$exception->id()] = true;
                $requestingGroup = $this->groupRepository->findById($exception->requestedByGroupId());
                \assert($requestingGroup !== null);
                $sentItems[] = new DashboardExceptionItem($exception, ExceptionDirection::Envoyee, $requestingGroup->name(), $requestingGroup->colorHex(), $slotsById[$exception->recurringSlotId()] ?? null);
            }
            foreach ($this->availabilityService->findArchivedForGroup($group->id(), $user->id()) as $exception) {
                if (isset($seenArchivedIds[$exception->id()])) {
                    continue;
                }
                $seenArchivedIds[$exception->id()] = true;
                $requestingGroup = $this->groupRepository->findById($exception->requestedByGroupId());
                \assert($requestingGroup !== null);
                $direction = $exception->requestedByGroupId() === $group->id() ? ExceptionDirection::Envoyee : ExceptionDirection::Recue;
                $archivedItems[] = new DashboardExceptionItem($exception, $direction, $requestingGroup->name(), $requestingGroup->colorHex(), $slotsById[$exception->recurringSlotId()] ?? null);
            }
        }

        $sortByCreatedAtDescending = static fn (DashboardExceptionItem $a, DashboardExceptionItem $b): int =>
            $b->exception()->createdAt() <=> $a->exception()->createdAt();
        usort($receivedItems, $sortByCreatedAtDescending);
        usort($sentItems, $sortByCreatedAtDescending);
        usort($archivedItems, $sortByCreatedAtDescending);

        // Limite connue : si l'utilisateur appartient à plusieurs groupes, le premier
        // (par ordre alphabétique de nom, cf. GroupRepository::findByMember) est affiché
        // arbitrairement dans le header. Pas de concept de "groupe principal" en base —
        // cf. issue à ouvrir si ce cas devient fréquent en usage réel.
        $primaryGroup = $groups[0] ?? null;
        $primaryGroupRole = $primaryGroup !== null ? $groupRoles[$primaryGroup->id()] : null;

        return new Response($this->renderer->render('dashboard/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'planningSlots' => $this->slotService->findFixedPlanningSlots(),
            'exceptionalPlanningSlots' => $this->slotService->findOccasionalPlanningSlots(),
            'receivedExceptions' => $receivedItems,
            'sentExceptions' => $sentItems,
            'archivedExceptions' => $archivedItems,
            'currentUserRole' => $user->role(),
            'currentUserGroupRoles' => $groupRoles,
            'currentUserGroupName' => $primaryGroup?->name(),
            'currentUserGroupRole' => $primaryGroupRole,
            'currentUserGroupId' => $primaryGroup?->id(),
            'currentUserInitials' => Initials::from($user->displayName()),
        ]));
    }

    public function adminSlots(): Response
    {
        $user = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/slots/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'slots' => $this->slotService->findAllActive(),
            'groups' => $this->groupService->findAll(),
            'currentUserRole' => $user->role(),
        ]));
    }

    public function groupSpace(Request $request, string $slug): Response
    {
        $group = $this->groupRepository->findBySlug($slug);
        if ($group === null) {
            throw new AccessDeniedException('Groupe introuvable.');
        }

        $user = $this->authGuard->currentUserOrNull();
        $isMember = $user !== null && $this->groupRepository->isMember($group->id(), $user->id());

        return new Response($this->renderer->render('group-space/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'group' => $group,
            'currentUserRole' => $user?->role(),
            'currentUserGroupRole' => $isMember ? $this->groupRepository->roleOf($group->id(), $user->id()) : null,
            'documents' => $isMember ? $this->groupDocumentRepository->findByGroup($group->id()) : [],
        ]));
    }

    public function adminGroups(): Response
    {
        $user = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/groups/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'groups' => $this->groupService->findAll(),
            'currentUserRole' => $user->role(),
        ]));
    }
}
