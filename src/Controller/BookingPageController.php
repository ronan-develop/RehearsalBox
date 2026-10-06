<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Presenter\MemberBookingsView;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Service\FreeSlotBookingPolicy;
use App\Service\FreeSlotBookingService;
use App\View\TemplateRendererInterface;

/** Page « Réserver le local » (#263) : le formulaire de réservation et les réservations des groupes de la personne. Rendu serveur ; les écritures passent par l'API. */
final class BookingPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly GroupRepositoryInterface $groups,
        private readonly FreeSlotBookingService $bookings,
        private readonly MemberBookingsView $view,
    ) {
    }

    public function index(): Response
    {
        $user = $this->authGuard->requireLogin();

        $groups = array_map(fn ($group): array => [
            'id' => $group->id(),
            'name' => $group->name(),
            'bookings' => $this->view->items($this->bookings->forGroup($user->id(), $group->id())),
        ], $this->groups->findByMember($user->id()));

        $today = new \DateTimeImmutable('today');

        return new Response($this->renderer->render('bookings/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'groups' => $groups,
            'minDate' => $today->format('Y-m-d'),
            'maxDate' => $today->modify('+' . FreeSlotBookingPolicy::MAX_DAYS_AHEAD . ' days')->format('Y-m-d'),
            'currentUserRole' => $user->role(),
        ]));
    }
}
