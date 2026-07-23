<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Service\Contract\AvailabilityServiceInterface;
use App\View\TemplateRendererInterface;

final class PageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AvailabilityServiceInterface $availabilityService,
        private readonly GroupRepositoryInterface $groupRepository,
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

        $exceptions = $this->availabilityService->findLiberatedBetween(
            new \DateTimeImmutable('today'),
            new \DateTimeImmutable('+30 days'),
        );
        $groups = $this->groupRepository->findByMember($user->id());

        return new Response($this->renderer->render('dashboard/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'exceptions' => $exceptions,
            'groups' => $groups,
        ]));
    }
}
