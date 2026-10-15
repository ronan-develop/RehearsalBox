<?php

declare(strict_types=1);

namespace App\Association\Controller;

use App\Account\Entity\UserRole;
use App\Association\Exception\AssociationTextNotFoundException;
use App\Association\Presenter\AssociationTextView;
use App\Association\Service\AssociationTextService;
use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/** Pages admin « Textes de l'association » (#472) : liste avec création, et un texte avec sa modification. Rendu serveur ; les écritures passent par l'API. */
final class AssociationTextPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AssociationTextService $texts,
        private readonly AssociationTextView $view,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/texts/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'rows' => $this->view->rows($this->texts->list($admin)),
            'currentUserRole' => $admin->role(),
        ]));
    }

    public function show(Request $request, string $id): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $textId = StrictId::orDenied($id);

        try {
            $text = $this->texts->get($admin, $textId);
        } catch (AssociationTextNotFoundException) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render('admin/texts/show', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'text' => $this->view->show($text),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
