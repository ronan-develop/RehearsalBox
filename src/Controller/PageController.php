<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dashboard\DashboardExceptionItem;
use App\Dashboard\DashboardRequestItem;
use App\Planning\Entity\ExceptionDirection;
use App\Account\Entity\UserRole;
use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\SlotException;
use App\Http\Request;
use App\Http\Response;
use App\Group\Repository\GroupDocumentRepositoryInterface;
use App\Account\Repository\NotificationPreferenceRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Dashboard\DashboardView;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Security\SafeRedirect;
use App\Planning\Service\AvailabilityServiceInterface;
use App\Group\Service\GroupServiceInterface;
use App\Planning\Service\SlotServiceInterface;
use App\Support\Initials;
use App\View\TemplateRendererInterface;

final class PageController
{
    /** Pages dont l'adresse porte un jeton : pas de Referer sortant, pas de mise en cache. */
    private const NO_REFERRER_NO_STORE = ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'];

    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SlotServiceInterface $slotService,
        private readonly GroupServiceInterface $groupService,
        private readonly GroupDocumentRepositoryInterface $groupDocumentRepository,
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly DashboardView $dashboard,
    ) {
    }

    /** `next` : retour après connexion (lien d'un e-mail), accepté seulement s'il désigne une page de la messagerie. */
    public function login(Request $request): Response
    {
        return new Response($this->renderer->render('auth/login', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'next' => SafeRedirect::afterLogin($request->query('next')),
        ]));
    }

    public function forgotPassword(): Response
    {
        return new Response($this->renderer->render('auth/forgot-password', ['csrfToken' => $this->csrfTokenManager->getToken()]));
    }

    /**
     * Le jeton voyage dans l'URL : la page interdit le Referer sortant et toute mise en cache.
     * Le jeton n'est validé qu'à l'envoi du formulaire (API), jamais ici.
     */
    public function resetPassword(Request $request): Response
    {
        $token = (string) $request->query('token', '');

        return new Response(
            $this->renderer->render('auth/reset-password', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'token' => $token,
            ]),
            headers: self::NO_REFERRER_NO_STORE,
        );
    }

    /** Page « Mon compte » (nom affiché et mot de passe) : réservée aux utilisateurs connectés. */
    public function accountPassword(): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response($this->renderer->render('account/password', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'currentUserRole' => $user->role(),
            'displayName' => $user->displayName(),
            'email' => $user->email(),
            'emailNotifications' => $this->preferences->emailEnabled($user->id()),
        ]));
    }

    /**
     * Cible du bouton « Ce n'est pas moi » du mail d'alerte. Un simple GET n'agit jamais (les scanners
     * de mails suivent les liens) : la page demande une confirmation explicite. Le jeton est dans l'URL :
     * pas de Referer sortant, pas de mise en cache.
     */
    public function secureAccount(Request $request): Response
    {
        return new Response(
            $this->renderer->render('auth/secure-account', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'token' => (string) $request->query('token', ''),
            ]),
            headers: self::NO_REFERRER_NO_STORE,
        );
    }

    /**
     * Cible du bouton du mail de confirmation, envoyé à la NOUVELLE adresse (#164). Un simple GET n'agit jamais
     * (les scanners de mails suivent les liens) : la page demande une confirmation explicite. Publique (le jeton
     * fait foi) ; le jeton est dans l'URL : pas de Referer sortant, pas de mise en cache.
     */
    public function confirmEmail(Request $request): Response
    {
        return new Response(
            $this->renderer->render('auth/confirm-email', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'token' => (string) $request->query('token', ''),
            ]),
            headers: self::NO_REFERRER_NO_STORE,
        );
    }

    public function dashboard(): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response($this->renderer->render('dashboard/index', ['csrfToken' => $this->csrfTokenManager->getToken()] + $this->dashboard->for($user)));
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

    public function groupSpace(Request $request, string $slug): Response
    {
        $group = $this->groupRepository->findBySlug($slug);
        if ($group === null) {
            throw new AccessDeniedException('Groupe introuvable.');
        }

        $user = $this->authGuard->currentUserOrNull();
        $isMember = $user !== null && $this->groupRepository->isMember($group->id(), $user->id());

        return new Response($this->renderer->render('group-space/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'group' => $group,
            'currentUserRole' => $user?->role(),
            'currentUserGroupRole' => $isMember ? $this->groupRepository->roleOf($group->id(), $user->id()) : null,
            'documents' => $isMember ? $this->groupDocumentRepository->findByGroup($group->id()) : [],
        ]));
    }
}
