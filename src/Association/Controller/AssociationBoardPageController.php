<?php

declare(strict_types=1);

namespace App\Association\Controller;

use App\Account\Entity\UserRole;
use App\Association\Presenter\AssociationBoardView;
use App\Association\Service\AssociationBoardService;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\View\TemplateRendererInterface;

/** Page admin « Bureau de l'association » (#478) : un bloc par poste. Rendu serveur ; les écritures passent par l'API. */
final class AssociationBoardPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AssociationBoardService $board,
        private readonly AssociationBoardView $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/board/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'posts' => $this->view->posts($this->board->holders($admin), $this->board->accounts($admin)),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
