<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ConversationSummary;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Presenter\ConversationPresenter;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;

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
                'total' => $this->conversationService->unreadCount($user->id()),
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

    /** Sans `after` : fil complet, marqué lu. Avec `after=<id>` : lecture incrémentale (polling). */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);

        $after = $request->query('after');
        if ($after !== null && (!is_string($after) || preg_match('/^[0-9]{1,10}$/', $after) !== 1)) {
            return new JsonResponse(['error' => 'Paramètre « after » invalide.'], 422);
        }

        $thread = $after === null
            ? $this->conversationService->open($user->id(), $conversationId)
            : $this->conversationService->poll($user->id(), $conversationId, (int) $after);

        return new JsonResponse($this->presenter->thread($thread, $user->id()));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);
        $message = $request->body('message');

        return $this->guarded(function () use ($user, $conversationId, $message): JsonResponse {
            $created = $this->conversationService->reply($user->id(), $conversationId, is_string($message) ? $message : '');

            return new JsonResponse(['message' => $this->presenter->message($created, null, $user->id())], 201);
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

    /** Signal « en train d'écrire » (le service et le dépôt limitent le débit). */
    public function typing(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->conversationService->typing($user->id(), $this->idOrDenied($id));

        return new JsonResponse(['status' => 'ok']);
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
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }

        throw new AccessDeniedException('Accès refusé.');
    }
}
