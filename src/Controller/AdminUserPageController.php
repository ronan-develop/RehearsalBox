<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Service\Contract\GroupServiceInterface;
use App\Service\Contract\UserAdminServiceInterface;
use App\View\TemplateRendererInterface;

/** Page admin « Utilisateurs » (#138) : liste des comptes et formulaire de création sans mot de passe. */
final class AdminUserPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly UserAdminServiceInterface $userAdminService,
        private readonly GroupServiceInterface $groupService,
    ) {
    }

    public function index(): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/users/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'items' => $this->userAdminService->listUsers(new \DateTimeImmutable()),
            'groups' => $this->groupService->findAll(),
            'currentUserId' => $admin->id(),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
