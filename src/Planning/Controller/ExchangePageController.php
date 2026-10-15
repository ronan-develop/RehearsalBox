<?php

declare(strict_types=1);

namespace App\Planning\Controller;

use App\Account\Entity\UserRole;
use App\Dashboard\DashboardView;
use App\Http\ErrorPage;
use App\Http\Request;
use App\Http\Response;
use App\Planning\Entity\ExceptionDirection;
use App\Planning\Service\AvailabilityServiceInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Page d'une demande d'échange de créneau (#462), cible du lien de l'e-mail : la carte de la demande, avec Accepter / Refuser en XHR
 * (le composant et le script du tableau de bord). Réservée au groupe titulaire et aux administrateurs ; la règle d'accès est dans le
 * service. Demande inexistante, identifiant mal formé ou accès interdit : le même 404, rien ne révèle l'existence d'une demande.
 */
final class ExchangePageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AvailabilityServiceInterface $availability,
        private readonly DashboardView $dashboard,
    ) {
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->authGuard->requireLogin();

        try {
            $exception = $this->availability->findForResponse(StrictId::orDenied($id), $user->id(), $user->hasRole(UserRole::Admin));
        } catch (AccessDeniedException) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render('exchanges/show', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'item' => $this->dashboard->itemFor($exception, ExceptionDirection::Recue),
            'currentUserRole' => $user->role(),
        ]));
    }
}
