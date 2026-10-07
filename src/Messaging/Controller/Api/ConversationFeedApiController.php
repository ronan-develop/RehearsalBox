<?php

declare(strict_types=1);

namespace App\Messaging\Controller\Api;

use App\Messaging\Entity\ConversationSummary;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Messaging\Presenter\ConversationPresenter;
use App\Messaging\Presenter\ConversationUpdates;
use App\Messaging\Presenter\MessagesPageView;
use App\Messaging\Repository\ConversationRepositoryInterface;
use App\Security\AuthGuard;
use App\Messaging\Service\ConversationReader;
use App\Messaging\Service\ConversationTrashService;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Messagerie, côté LECTURE : listes, mises à jour d'un fil (polling) et signal « en train d'écrire ». L'identité vient
 * UNIQUEMENT de la session ; un identifiant mal formé est refusé comme un accès interdit. L'écriture est dans
 * ConversationApiController.
 */
final class ConversationFeedApiController
{
    private const BOXES = [ConversationRepositoryInterface::BOX_ACTIVE, ConversationRepositoryInterface::BOX_ARCHIVED];

    public function __construct(
        private readonly ConversationReader $reader,
        private readonly ConversationUpdates $updates,
        private readonly ConversationPresenter $presenter,
        private readonly AuthGuard $authGuard,
        private readonly MessagesPageView $view,
        private readonly TemplateRendererInterface $renderer,
        private readonly ConversationTrashService $trashService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $box = $request->query('box', ConversationRepositoryInterface::BOX_ACTIVE);
        if (!is_string($box) || !in_array($box, self::BOXES, true)) {
            return new JsonResponse(['error' => 'Liste invalide.'], 422);
        }

        return new JsonResponse([
            'conversations' => array_map(
                fn (ConversationSummary $summary): array => $this->presenter->summary($summary, $user->id()),
                $this->reader->listFor($user->id(), $box),
            ),
            'unread' => [
                'total' => $this->reader->unreadCount($user->id()) + $this->trashService->alertCount($user->id()),
                'alerts' => $this->trashService->alertCount($user->id()),
                'archived' => $this->reader->unreadCount($user->id(), ConversationRepositoryInterface::BOX_ARCHIVED),
            ],
        ]);
    }

    /** Liste des conversations en fragment HTML (rafraîchissement de la colonne de gauche). */
    public function listFragment(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $box = $request->query('box', ConversationRepositoryInterface::BOX_ACTIVE);
        if (!is_string($box) || !in_array($box, self::BOXES, true)) {
            return new JsonResponse(['error' => 'Liste invalide.'], 422);
        }
        $active = $request->query('active');
        $activeId = $active === null ? null : StrictId::from($active);

        $sidebar = $this->view->sidebar($user->id(), $activeId, $box);

        return new JsonResponse([
            'html' => $this->renderer->render('messages/_conversation-items', ['items' => $sidebar['items']]),
            'empty' => $sidebar['items'] === [],
            'archivedUnread' => $sidebar['archivedUnread'],
        ]);
    }

    /** Mises à jour d'un fil ouvert : les messages plus récents que `after` et, avec `editedAfter`, les corrections reçues depuis. */
    public function updates(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = StrictId::orDenied($id);

        $after = $request->query('after', '0');
        if (!is_string($after) || preg_match('/^[0-9]{1,10}$/', $after) !== 1) {
            return new JsonResponse(['error' => 'Paramètre « after » invalide.'], 422);
        }

        // Curseur des corrections déjà reçues (secondes Unix) : sans lui, aucune correction n'est renvoyée.
        $editedAfter = $request->query('editedAfter');
        if ($editedAfter !== null && (!is_string($editedAfter) || preg_match('/^[0-9]{1,12}$/', $editedAfter) !== 1)) {
            return new JsonResponse(['error' => 'Paramètre « editedAfter » invalide.'], 422);
        }

        return new JsonResponse($this->updates->payload($user->id(), $conversationId, (int) $after, $editedAfter === null ? null : (int) $editedAfter));
    }

    /** Signal « en train d'écrire » (le service et le dépôt limitent le débit). */
    public function typing(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->reader->typing($user->id(), StrictId::orDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }
}
