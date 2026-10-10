<?php

declare(strict_types=1);

namespace App\Messaging\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Messaging\Presenter\MessagesPageView;
use App\Messaging\Repository\ConversationRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Messaging\Service\ConversationReader;
use App\Messaging\Service\Direct\DirectMemberListService;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Pages de la messagerie (#169, #183) : `/messages` (liste), `/messages/archives`, `/messages/{id}` (une conversation, une
 * route), `/messages/new/{groupId}` (brouillon) et les messages directs (#269) : `/messages/direct` (tous les membres) puis
 * `/messages/direct/{userId}` (brouillon adressé à une personne). Navigation classique : chaque URL est une page rendue par le serveur,
 * le JS n'ajoute que le direct (nouveaux messages, envoi, titre).
 */
final class MessagesPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly ConversationReader $conversationReader,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly MessagesPageView $view,
        private readonly DirectMemberListService $directMembers,
    ) {
    }

    public function list(Request $request): Response
    {
        $user = $this->authGuard->requireLogin();

        return $this->render($this->view->sidebar($user->id(), null));
    }

    /** Conversations archivées (sans message depuis 30 jours) : une vraie page, pas un état du JS. */
    public function archives(Request $request): Response
    {
        $user = $this->authGuard->requireLogin();

        return $this->render($this->view->sidebar($user->id(), null, ConversationRepositoryInterface::BOX_ARCHIVED));
    }

    /** Corbeille (#190) : ce que l'initiateur a supprimé, restaurable pendant 30 jours. */
    public function trash(Request $request): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response(
            $this->renderer->render('messages/trash', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'items' => $this->view->trash($user->id()),
            ]),
        );
    }

    /**
     * Une conversation : la page est rendue par le serveur (liste, titre, messages, « vu par »). L'ouvrir la marque lue
     * pour cette personne (pas un simple préchargement du navigateur). Identifiant mal formé, inexistant ou interdit : même refus, rien ne révèle l'existence d'un fil.
     */
    public function show(Request $request, string $id): Response
    {
        $user = $this->authGuard->requireLogin();

        $conversationId = StrictId::from($id) ?? throw new AccessDeniedException('Accès refusé.');
        $thread = $this->conversationReader->open($user->id(), $conversationId, !$request->isPrefetch());

        return $this->render(
            $this->view->sidebar($user->id(), $conversationId),
            $this->view->thread($thread, $user->id()),
        );
    }

    /**
     * Page de démarrage (#181) : un fil vide adressé au groupe visé. Rien n'est créé ici : la conversation naît à
     * l'envoi du premier message (POST /api/conversations, qui revérifie tout). Groupe inconnu ou identifiant mal formé :
     * même refus qu'ailleurs.
     */
    public function compose(Request $request, string $groupId): Response
    {
        $user = $this->authGuard->requireLogin();

        $targetId = StrictId::from($groupId) ?? throw new AccessDeniedException('Accès refusé.');
        $target = $this->groupRepository->findById($targetId) ?? throw new AccessDeniedException('Accès refusé.');

        // Un groupe ne s'écrit pas à lui-même : le groupe visé n'est jamais proposé comme émetteur.
        $senders = [];
        foreach ($this->groupRepository->findByMember($user->id()) as $group) {
            if ($group->id() !== $target->id()) {
                $senders[] = ['id' => $group->id(), 'name' => $group->name()];
            }
        }

        return $this->render($this->view->sidebar($user->id(), null), null, [
            'targetId' => $target->id(),
            'targetName' => $target->name(),
            'senders' => $senders,
            'blocked' => $senders === [],
        ]);
    }

    /** Nouveau message (#269) : tous les membres actifs, nom et groupes, jamais d'adresse ; le filtre par nom se fait dans le navigateur. */
    public function direct(Request $request): Response
    {
        $user = $this->authGuard->requireLogin();

        $members = array_map(
            static fn ($member): array => ['id' => $member->id(), 'name' => $member->name(), 'groups' => implode(', ', $member->groupNames())],
            $this->directMembers->members($user->id()),
        );

        return $this->render($this->view->sidebar($user->id(), null), null, null, $members);
    }

    /**
     * Brouillon d'un message direct : un fil vide adressé à une personne, sans choix de groupe émetteur. Rien n'est créé ici : la
     * conversation naît au premier message (POST /api/conversations/direct, qui revérifie tout). Soi-même, personne inconnue
     * ou inactive, identifiant mal formé : même refus qu'ailleurs.
     */
    public function directCompose(Request $request, string $userId): Response
    {
        $user = $this->authGuard->requireLogin();

        $targetId = StrictId::from($userId) ?? throw new AccessDeniedException('Accès refusé.');
        $target = $this->directMembers->recipient($user->id(), $targetId);

        return $this->render($this->view->sidebar($user->id(), null), null, [
            'targetId' => $target->id(),
            'targetName' => $target->displayName(),
            'senders' => [],
            'blocked' => false,
            'direct' => true,
        ]);
    }

    /**
     * @param array{items: list<array<string, mixed>>, box: string, archivedUnread: int, alerts: list<array{id: int, text: string, url: ?string}>, trashCount: int} $sidebar
     * @param array<string, mixed>|null                                                                                                                                  $thread
     * @param array{targetId: int, targetName: string, senders: list<array{id: int, name: string}>, blocked: bool, direct?: bool}|null                                  $draft
     * @param list<array{id: int, name: string, groups: string}>|null                                                                                                    $picker membres proposés par « Nouveau message »
     */
    private function render(array $sidebar, ?array $thread = null, ?array $draft = null, ?array $picker = null): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response(
            $this->renderer->render('messages/index', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'currentUserRole' => $user->role(),
                'currentUserId' => $user->id(),
                'sidebar' => $sidebar,
                'thread' => $thread,
                'draft' => $draft,
                'picker' => $picker,
            ]),
        );
    }
}
