<?php

declare(strict_types=1);

namespace App\Assembly\Controller;

use App\Account\Entity\UserRole;
use App\Assembly\Exception\AssemblyNotFoundException;
use App\Assembly\Presenter\AssemblyFormView;
use App\Assembly\Presenter\AssemblyListView;
use App\Assembly\Presenter\AssemblyMinutesView;
use App\Assembly\Service\AssemblyReader;
use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/** Pages admin des comptes rendus d'AG (#361) : liste, formulaire (ou résumé si validé) et version imprimable. Rendu serveur ; les écritures passent par l'API. */
final class AssemblyPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AssemblyReader $reader,
        private readonly AssemblyListView $list,
        private readonly AssemblyFormView $form,
        private readonly AssemblyMinutesView $minutes,
        private readonly string $associationName,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/assemblies/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'rows' => $this->list->rows($this->reader->list($admin)),
            'currentUserRole' => $admin->role(),
        ]));
    }

    public function edit(Request $request, string $id): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $assemblyId = StrictId::orDenied($id);

        try {
            $found = $this->reader->minutes($admin, $assemblyId);
        } catch (AssemblyNotFoundException) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render('admin/assemblies/edit', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'form' => $this->form->build($found['assembly'], $found['content'], $this->reader->candidates($admin), $this->reader->boardCandidates($admin), $found['successor'], $this->reader->boardNames($admin)),
            'currentUserRole' => $admin->role(),
        ]));
    }

    public function print(Request $request, string $id): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $assemblyId = StrictId::orDenied($id);

        try {
            $found = $this->reader->minutes($admin, $assemblyId);
        } catch (AssemblyNotFoundException) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render('admin/assemblies/print', [
            'minutes' => $this->minutes->build($found['assembly'], $found['content'], $this->associationName, $found['successor']),
            'backHref' => '/admin/assemblies/' . $assemblyId,
        ]));
    }
}
