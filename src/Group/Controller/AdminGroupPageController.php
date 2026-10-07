<?php

declare(strict_types=1);

namespace App\Group\Controller;

use App\Entity\Enum\UserRole;
use App\Http\Response;
use App\Group\Repository\GroupImpactRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Group\Service\GroupServiceInterface;
use App\View\TemplateRendererInterface;

/**
 * Page admin « Groupes » : liste et formulaires. Chaque bouton « Supprimer » porte ce que la suppression emporterait (membres,
 * conversations, documents, demandes de créneau) pour que la confirmation soit chiffrée (#224).
 */
final class AdminGroupPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly GroupServiceInterface $groupService,
        private readonly GroupImpactRepositoryInterface $impacts,
    ) {
    }

    public function index(): Response
    {
        $user = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/groups/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'groups' => $this->groupService->findAll(),
            'impacts' => $this->impacts->countsByGroup(),
            'currentUserRole' => $user->role(),
        ]));
    }
}
