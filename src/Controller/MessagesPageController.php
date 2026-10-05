<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
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
        if (preg_match('/^[1-9][0-9]{0,9}$/', $id) !== 1) {
            throw new AccessDeniedException('Accès refusé.');
        }
        $conversation = $this->conversationService->find($user->id(), (int) $id);

        return $this->render($conversation->id());
    }

    private function render(?int $activeId): Response
    {
        $user = $this->authGuard->requireLogin();

        return new Response(
            $this->renderer->render('messages/index', [
                'csrfToken' => $this->csrfTokenManager->getToken(),
                'currentUserRole' => $user->role(),
                'activeId' => $activeId,
            ]),
            headers: ['Cache-Control' => 'private, no-store'],
        );
    }
}
