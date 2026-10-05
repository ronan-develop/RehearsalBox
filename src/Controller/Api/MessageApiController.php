<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Presenter\EditedMessageFragments;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use App\Service\MessageEditService;
use App\Support\StrictId;

/**
 * Éditer son message (#200). L'identité vient UNIQUEMENT de la session ; un identifiant mal formé est refusé comme un accès
 * interdit. Les droits (auteur seul, 15 minutes, jamais une ligne système) sont dans MessageEditService.
 */
final class MessageApiController
{
    public function __construct(
        private readonly MessageEditService $editor,
        private readonly AuthGuard $authGuard,
        private readonly EditedMessageFragments $fragments,
        private readonly ConversationService $conversationService,
    ) {
    }

    /** `message` : le nouveau texte ; `mentions` : nouvelles personnes taguées (celles déjà mentionnées le restent tant que leur « @Nom » est dans le texte). */
    public function edit(Request $request, string $id, string $messageId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);
        $messageId = $this->idOrDenied($messageId);

        $text = $request->body('message');
        $mentions = $request->body('mentions');
        if (!is_string($text)) {
            return new JsonResponse(['error' => 'Le message est requis.', 'fields' => ['message' => 'Le message est requis.']], 422);
        }
        if ($mentions !== null && (!is_array($mentions) || !array_is_list($mentions))) {
            return new JsonResponse(['error' => 'Mentions invalides.', 'fields' => ['mentions' => 'La liste des personnes mentionnées est invalide.']], 422);
        }

        try {
            $message = $this->editor->edit($user->id(), $conversationId, $messageId, $text, $mentions ?? []);
        } catch (ConversationValidationException $e) {
            return new JsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422);
        } catch (ConversationRateLimitException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 429);
        }

        // Le corps corrigé, dessiné par le même gabarit que la page ; relu avec ses mentions pour le surlignage.
        $since = ($message->editedAt() ?? $message->createdAt())->modify('-1 second');
        $edited = $this->conversationService->edited($user->id(), $conversationId, $since, $message->id());

        return new JsonResponse([
            'status' => 'ok',
            'edited' => $this->fragments->fragments($edited['messages'], $edited['mentions'], $user->id()),
            'editedAt' => EditedMessageFragments::cursor($edited['messages']),
        ]);
    }

    private function idOrDenied(mixed $value): int
    {
        return StrictId::from($value) ?? throw new AccessDeniedException('Accès refusé.');
    }
}
