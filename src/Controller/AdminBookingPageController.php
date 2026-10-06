<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Enum\UserRole;
use App\Http\Response;
use App\Presenter\AdminBookingsView;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Service\FreeSlotBookingService;
use App\View\TemplateRendererInterface;

/** Page admin « Réservations » (#263) : les réservations libres à valider ou refuser. Rendu serveur ; les décisions passent par l'API. */
final class AdminBookingPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly FreeSlotBookingService $bookings,
        private readonly AdminBookingsView $view,
    ) {
    }

    public function index(): Response
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/bookings/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'items' => $this->view->items($this->bookings->pending()),
            'currentUserRole' => $admin->role(),
        ]));
    }
}
