<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ConversationSummary;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Presenter\ConversationPresenter;
use App\Presenter\MessagesPageView;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationGuestService;
use App\Service\ConversationService;
use App\Service\ConversationTrashService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use App\Support\StrictId;
use App\View\TemplateRendererInterface;

/**
 * Messagerie entre groupes (#153, #169). Les identifiants de l'auteur et de l'utilisateur viennent UNIQUEMENT de la
 * session ; un identifiant mal formé est refusé comme un accès interdit. Contrôleur mince : lit la requête, appelle
 * le service, présente le résultat (ConversationPresenter).
 */
final class ConversationApiController
{
    private const BOXES = [ConversationRepositoryInterface::BOX_ACTIVE, ConversationRepositoryInterface::BOX_ARCHIVED];

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationPresenter $presenter,
        private readonly AuthGuard $authGuard,
        private readonly MessagesPageView $view,
        private readonly TemplateRendererInterface $renderer,
        private readonly ConversationTrashService $trashService,
        private readonly ConversationGuestService $guestService,
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
                $this->conversationService->listFor($user->id(), $box),
            ),
            'unread' => [
                'total' => $this->conversationService->unreadCount($user->id()) + $this->trashService->alertCount($user->id()),
                'alerts' => $this->trashService->alertCount($user->id()),
                'archived' => $this->conversationService->unreadCount($user->id(), ConversationRepositoryInterface::BOX_ARCHIVED),
            ],
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $initiatorGroupId = $this->idOrDenied($request->body('groupId'));
        $targetGroupId = $this->idOrDenied($request->body('targetGroupId'));
        $message = $request->body('message');
        $title = $request->body('title');

        return $this->guarded(function () use ($user, $initiatorGroupId, $targetGroupId, $message, $title): JsonResponse {
            $conversation = $this->conversationService->start(
                $user->id(),
                $initiatorGroupId,
                $targetGroupId,
                is_string($message) ? $message : '',
                is_string($title) ? $title : null,
            );

            return new JsonResponse(['id' => $conversation->id()], 201);
        });
    }

    /**
     * Mises à jour d'un fil ouvert (polling) : les messages plus récents que `after`, déjà dessinés par le serveur
     * (fragment HTML issu du même gabarit que la page), avec qui écrit, « vu par » et le titre. Recevoir en direct un
     * message des autres vaut lecture.
     */
    public function updates(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);

        $after = $request->query('after', '0');
        if (!is_string($after) || preg_match('/^[0-9]{1,10}$/', $after) !== 1) {
            return new JsonResponse(['error' => 'Paramètre « after » invalide.'], 422);
        }

        return $this->updatesResponse($user->id(), $conversationId, (int) $after);
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

    /** Envoie un message ; la réponse contient les messages plus récents que `after` (dont le mien), déjà dessinés. */
    public function reply(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);
        $message = $request->body('message');
        $after = $request->body('after');

        return $this->guarded(function () use ($user, $conversationId, $message, $after): JsonResponse {
            $created = $this->conversationService->reply($user->id(), $conversationId, is_string($message) ? $message : '');
            $anchor = StrictId::from($after) ?? max(0, $created->id() - 1);

            return $this->updatesResponse($user->id(), $conversationId, min($anchor, $created->id() - 1), 201);
        });
    }

    /** Titre : texte, ou null / vide pour le retirer. */
    public function rename(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);

        $body = $request->allBody();
        if (!array_key_exists('title', $body) || !(is_string($body['title']) || $body['title'] === null)) {
            return new JsonResponse(['error' => 'Le titre est invalide.'], 422);
        }
        $title = $body['title'];

        return $this->guarded(function () use ($user, $conversationId, $title): JsonResponse {
            $this->conversationService->rename($user->id(), $conversationId, $title);

            return new JsonResponse(['status' => 'ok']);
        });
    }

    /** Met la conversation à la corbeille (initiateur seulement). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trashService->delete($user->id(), $this->idOrDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trashService->restore($user->id(), $this->idOrDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    /** Suppression définitive d'une conversation déjà à la corbeille. */
    public function destroyPermanently(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trashService->deletePermanently($user->id(), $this->idOrDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    /** Ferme un avis de la personne connectée (celui d'un autre est ignoré). */
    public function dismissAlert(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->trashService->dismissAlert($user->id(), $this->idOrDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    /** Signal « en train d'écrire » (le service et le dépôt limitent le débit). */
    public function typing(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->conversationService->typing($user->id(), $this->idOrDenied($id));

        return new JsonResponse(['status' => 'ok']);
    }

    private function updatesResponse(int $userId, int $conversationId, int $after, int $status = 200): JsonResponse
    {
        $view = $this->view->thread($this->conversationService->poll($userId, $conversationId, $after), $userId);

        return new JsonResponse([
            'html' => $this->renderer->render('messages/_rows', ['rows' => $view['rows']]),
            'lastId' => $view['lastId'],
            'hasNew' => $view['rows'] !== [],
            'status' => $view['status'],
            'typing' => $view['typing'],
            'title' => $view['title'],
            'displayTitle' => $view['displayTitle'],
            'label' => $view['label'],
        ], $status);
    }

    /** @param callable(): JsonResponse $action */
    private function guarded(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (ConversationValidationException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422);
        } catch (ConversationRateLimitException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 429);
        }
    }

    private function idOrDenied(mixed $value): int
    {
        return StrictId::from($value) ?? throw new AccessDeniedException('Accès refusé.');
    }
}
