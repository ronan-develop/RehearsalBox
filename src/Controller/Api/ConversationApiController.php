<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Presenter\ConversationUpdates;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationGuestService;
use App\Service\ConversationService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use App\Support\StrictId;

/**
 * Messagerie entre groupes, côté ÉCRITURE (#153, #169) : démarrer, répondre, renommer, retirer un invité. Les identifiants de
 * l'auteur et de l'utilisateur viennent UNIQUEMENT de la session ; un identifiant mal formé est refusé comme un accès
 * interdit. Contrôleur mince : lit la requête, appelle le service. La lecture est dans ConversationFeedApiController.
 */
final class ConversationApiController
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly ConversationUpdates $updates,
        private readonly AuthGuard $authGuard,
        private readonly ConversationGuestService $guestService,
    ) {
    }

    public function start(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $initiatorGroupId = StrictId::orDenied($request->body('groupId'));
        $targetGroupId = StrictId::orDenied($request->body('targetGroupId'));
        $message = $request->body('message');
        $title = $request->body('title');
        $mentions = $request->body('mentions');

        return $this->guarded(function () use ($user, $initiatorGroupId, $targetGroupId, $message, $title, $mentions): JsonResponse {
            $conversation = $this->conversationService->start(
                $user->id(),
                $initiatorGroupId,
                $targetGroupId,
                is_string($message) ? $message : '',
                is_string($title) ? $title : null,
                $this->mentionIds($mentions),
            );

            return new JsonResponse(['id' => $conversation->id()], 201);
        });
    }

    /** Envoie un message ; la réponse contient les messages plus récents que `after` (dont le mien), déjà dessinés. */
    public function reply(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = StrictId::orDenied($id);
        $message = $request->body('message');
        $after = $request->body('after');
        $mentions = $request->body('mentions');
        // Message cité (#214) : absent ou null = aucune citation ; un identifiant mal formé est refusé comme un accès interdit.
        $replyTo = $request->body('replyTo');
        $replyToId = $replyTo === null ? null : StrictId::orDenied($replyTo);

        return $this->guarded(function () use ($user, $conversationId, $message, $after, $mentions, $replyToId): JsonResponse {
            $created = $this->conversationService->reply($user->id(), $conversationId, is_string($message) ? $message : '', $this->mentionIds($mentions), $replyToId);
            $anchor = StrictId::from($after) ?? max(0, $created->id() - 1);

            return new JsonResponse($this->updates->payload($user->id(), $conversationId, min($anchor, $created->id() - 1)), 201);
        });
    }

    /** Titre : texte, ou null / vide pour le retirer. */
    public function rename(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = StrictId::orDenied($id);

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

    /** Retire un invité (celui qui l'a ajouté, l'initiateur de la conversation, ou l'invité qui quitte). */
    public function removeGuest(Request $request, string $id, string $userId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $this->guestService->remove($user->id(), StrictId::orDenied($id), StrictId::orDenied($userId));

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

    /**
     * Identifiants des personnes taguées : absent ou null = aucune ; un autre type que la liste est refusé (422).
     *
     * @return list<mixed> identifiants non fiables, validés par le service
     */
    private function mentionIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConversationValidationException(['mentions' => 'La liste des personnes mentionnées est invalide.']);
        }

        return $value;
    }

}
