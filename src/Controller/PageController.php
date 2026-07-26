<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DashboardExceptionItem;
use App\Entity\Enum\ExceptionDirection;
use App\Entity\Enum\UserRole;
use App\Http\RedirectResponse;
use App\Http\Response;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Contract\GroupServiceInterface;
use App\Service\Contract\SlotServiceInterface;
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

        $items = [];
        $seenPendingIds = [];
        $seenRequestedIds = [];
        foreach ($groups as $group) {
            foreach ($this->availabilityService->findPendingForHolderGroup($group->id(), $user->id()) as $exception) {
                if (isset($seenPendingIds[$exception->id()])) {
                    continue;
                }
                $seenPendingIds[$exception->id()] = true;
                $items[] = new DashboardExceptionItem($exception, ExceptionDirection::Recue);
            }
            foreach ($this->availabilityService->findByRequestingGroup($group->id(), $user->id()) as $exception) {
                if (isset($seenRequestedIds[$exception->id()])) {
                    continue;
                }
                $seenRequestedIds[$exception->id()] = true;
                $items[] = new DashboardExceptionItem($exception, ExceptionDirection::Envoyee);
            }
        }

        usort($items, static fn (DashboardExceptionItem $a, DashboardExceptionItem $b): int =>
            $b->exception()->createdAt() <=> $a->exception()->createdAt());

        return new Response($this->renderer->render('dashboard/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'planningSlots' => $this->slotService->findPlanningSlots(),
            'dashboardExceptions' => $items,
            'currentUserRole' => $user->role(),
        ]));
    }

    public function groupContact(int $id): Response
    {
        $this->authGuard->requireLogin();

        $group = $this->groupRepository->findById($id);
        if ($group === null) {
            return new Response('', 404);
        }

        return new RedirectResponse('mailto:' . $group->contactEmail());
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
