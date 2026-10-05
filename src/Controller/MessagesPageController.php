<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Pages de la messagerie (#169) : `/messages` (liste) et `/messages/{id}` (une conversation, une route). Le gabarit est
 * le même ; le contenu (liste, messages) est servi par l'API et rempli par chat.js. La page ne marque rien comme lu :
 * c'est l'ouverture du fil par le JS qui le fait.
 */
final class MessagesPageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
        private readonly AuthGuard $authGuard,
        private readonly ConversationService $conversationService,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    public function list(): Response
    {
        return $this->render(null);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->authGuard->requireLogin();

        // Identifiant mal formé, inexistant ou interdit : même refus (rien ne révèle l'existence d'une conversation).
        $conversationId = StrictId::from($id) ?? throw new AccessDeniedException('Accès refusé.');
        $conversation = $this->conversationService->find($user->id(), $conversationId);

        return $this->render($conversation->id());
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

        return $this->render(null, [
            'targetId' => $target->id(),
            'targetName' => $target->name(),
            'senders' => $senders,
            'blocked' => $senders === [],
        ]);
    }

    /** @param array{targetId: int, targetName: string, senders: list<array{id: int, name: string}>, blocked: bool}|null $draft */
    private function render(?int $activeId, ?array $draft = null): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response(
            $this->renderer->render('messages/index', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'currentUserRole' => $user->role(),
                'activeId' => $activeId,
                'draft' => $draft,
            ]),
            headers: ['Cache-Control' => 'private, no-store'],
        );
    }
}
