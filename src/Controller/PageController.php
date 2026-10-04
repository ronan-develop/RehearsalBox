<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\DashboardExceptionItem;
use App\Entity\Enum\ExceptionDirection;
use App\Entity\Enum\UserRole;
use App\Entity\RecurringSlot;
use App\Entity\SlotException;
use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\AvailabilityServiceInterface;
use App\Service\Contract\GroupServiceInterface;
use App\Service\Contract\SlotServiceInterface;
use App\Support\Initials;
use App\View\TemplateRendererInterface;

final class PageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly AvailabilityServiceInterface $availabilityService,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly SlotServiceInterface $slotService,
        private readonly GroupServiceInterface $groupService,
        private readonly GroupDocumentRepositoryInterface $groupDocumentRepository,
    ) {
    }

    public function login(): Response
    {
        return new Response($this->renderer->render('auth/login', ['csrfToken' => $this->csrfTokenManager->getToken()]));
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
            headers: ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'],
        );
    }

    /** Page « Mon mot de passe » : réservée aux utilisateurs connectés. */
    public function accountPassword(): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response($this->renderer->render('account/password', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'currentUserRole' => $user->role(),
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
            headers: ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'],
        );
    }

    public function register(): Response
    {
        return new Response($this->renderer->render('auth/register', ['csrfToken' => $this->csrfTokenManager->getToken()]));
    }

    public function dashboard(): Response
    {
        $user = $this->authGuard->requireLogin();
        $groups = $this->groupRepository->findByMember($user->id());

        $groupRoles = [];
        foreach ($groups as $group) {
            $groupRoles[$group->id()] = $this->groupRepository->roleOf($group->id(), $user->id());
        }

        $slotsById = [];
        foreach ($this->slotService->findAllActive() as $slot) {
            $slotsById[$slot->id()] = $slot;
        }

        // Indexés par id d'exception : une demande visible depuis plusieurs groupes
        // de l'utilisateur n'apparaît qu'une fois (et n'est construite qu'une fois).
        $receivedItems = [];
        $sentItems = [];
        $archivedItems = [];
        foreach ($groups as $group) {
            foreach ($this->availabilityService->findPendingForHolderGroup($group->id(), $user->id()) as $exception) {
                $receivedItems[$exception->id()] ??= $this->toDashboardItem($exception, ExceptionDirection::Recue, $slotsById);
            }
            foreach ($this->availabilityService->findByRequestingGroup($group->id(), $user->id()) as $exception) {
                $sentItems[$exception->id()] ??= $this->toDashboardItem($exception, ExceptionDirection::Envoyee, $slotsById);
            }
            foreach ($this->availabilityService->findArchivedForGroup($group->id(), $user->id()) as $exception) {
                $direction = $exception->requestedByGroupId() === $group->id() ? ExceptionDirection::Envoyee : ExceptionDirection::Recue;
                $archivedItems[$exception->id()] ??= $this->toDashboardItem($exception, $direction, $slotsById);
            }
        }

        $sortByCreatedAtDescending = static fn (DashboardExceptionItem $a, DashboardExceptionItem $b): int =>
            $b->exception()->createdAt() <=> $a->exception()->createdAt();
        usort($receivedItems, $sortByCreatedAtDescending);
        usort($sentItems, $sortByCreatedAtDescending);
        usort($archivedItems, $sortByCreatedAtDescending);

        // Limite connue : si l'utilisateur appartient à plusieurs groupes, le premier
        // (par ordre alphabétique de nom, cf. GroupRepository::findByMember) est affiché
        // arbitrairement dans le header. Pas de concept de "groupe principal" en base —
        // cf. issue à ouvrir si ce cas devient fréquent en usage réel.
        $primaryGroup = $groups[0] ?? null;
        $primaryGroupRole = $primaryGroup !== null ? $groupRoles[$primaryGroup->id()] : null;

        return new Response($this->renderer->render('dashboard/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'planningSlots' => $this->slotService->findFixedPlanningSlots(),
            'exceptionalPlanningSlots' => $this->slotService->findOccasionalPlanningSlots(),
            'receivedExceptions' => $receivedItems,
            'sentExceptions' => $sentItems,
            'archivedExceptions' => $archivedItems,
            'currentUserRole' => $user->role(),
            'currentUserGroupRoles' => $groupRoles,
            'currentUserGroupName' => $primaryGroup?->name(),
            'currentUserGroupRole' => $primaryGroupRole,
            'currentUserGroupId' => $primaryGroup?->id(),
            'currentUserInitials' => Initials::from($user->displayName()),
        ]));
    }

    /** @param array<int, RecurringSlot> $slotsById */
    private function toDashboardItem(SlotException $exception, ExceptionDirection $direction, array $slotsById): DashboardExceptionItem
    {
        $requestingGroup = $this->groupRepository->findById($exception->requestedByGroupId());
        \assert($requestingGroup !== null);

        return new DashboardExceptionItem(
            $exception,
            $direction,
            $requestingGroup->name(),
            $requestingGroup->colorHex(),
            $slotsById[$exception->recurringSlotId()] ?? null,
        );
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

    public function adminGroups(): Response
    {
        $user = $this->authGuard->requireRole(UserRole::Admin);

        return new Response($this->renderer->render('admin/groups/index', [
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'groups' => $this->groupService->findAll(),
            'currentUserRole' => $user->role(),
        ]));
    }
}
