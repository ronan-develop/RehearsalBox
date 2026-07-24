<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
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

        $pending = [];
        $requested = [];
        foreach ($groups as $group) {
            $pending = [...$pending, ...$this->availabilityService->findPendingForHolderGroup($group->id(), $user->id())];
            $requested = [...$requested, ...$this->availabilityService->findByRequestingGroup($group->id(), $user->id())];
        }

        return new Response($this->renderer->render('dashboard/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'pendingExceptions' => $pending,
            'requestedExceptions' => $requested,
            'requestableSlots' => $this->availabilityService->findRequestableSlotsFor($user->id()),
            'groups' => $groups,
            'currentUserRole' => $user->role(),
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
