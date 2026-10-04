<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\ConversationMessage;
use App\Entity\ConversationSummary;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Repository\Contract\ConversationRepositoryInterface;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;

/**
 * Messagerie entre groupes (#153). Les identifiants de l'auteur et de l'utilisateur viennent
 * UNIQUEMENT de la session ; un identifiant mal formé est refusé comme un accès interdit.
 */
final class ConversationApiController
{
    private const BOXES = [
        ConversationRepositoryInterface::BOX_RECEIVED,
        ConversationRepositoryInterface::BOX_SENT,
        ConversationRepositoryInterface::BOX_ARCHIVED,
    ];

    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $box = $request->query('box', ConversationRepositoryInterface::BOX_RECEIVED);
        if (!is_string($box) || !in_array($box, self::BOXES, true)) {
            return new JsonResponse(['error' => 'Boîte invalide.'], 422);
        }

        return new JsonResponse([
            'conversations' => array_map(
                fn (ConversationSummary $summary): array => $this->summaryToArray($summary, $user->id()),
                $this->conversationService->listFor($user->id(), $box),
            ),
            'unread' => $this->conversationService->unreadCount($user->id()),
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $initiatorGroupId = $this->idOrDenied($request->body('groupId'));
        $targetGroupId = $this->idOrDenied($request->body('targetGroupId'));
        $subject = $request->body('subject');
        $message = $request->body('message');

        return $this->guarded(function () use ($user, $initiatorGroupId, $targetGroupId, $subject, $message): JsonResponse {
            $conversation = $this->conversationService->start(
                $user->id(),
                $initiatorGroupId,
                $targetGroupId,
                is_string($subject) ? $subject : '',
                is_string($message) ? $message : '',
            );

            return new JsonResponse(['id' => $conversation->id()], 201);
        });
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);

        $thread = $this->conversationService->open($user->id(), $conversationId);

        return new JsonResponse([
            'id' => $thread->conversation()->id(),
            'subject' => $thread->conversation()->subject(),
            'label' => $thread->label(),
            'messages' => array_map(fn (ConversationMessage $m): array => $this->messageToArray($m, $user->id()), $thread->messages()),
        ]);
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);
        $message = $request->body('message');

        return $this->guarded(function () use ($user, $conversationId, $message): JsonResponse {
            $created = $this->conversationService->reply($user->id(), $conversationId, is_string($message) ? $message : '');

            return new JsonResponse(['message' => $this->messageToArray($created, $user->id())], 201);
        });
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();
        $conversationId = $this->idOrDenied($id);

        $archived = $request->body('archived');
        if (!is_bool($archived)) {
            // Contrôle d'accès d'abord : un étranger reçoit 403 quelle que soit la forme de la requête.
            $this->conversationService->open($user->id(), $conversationId);

            return new JsonResponse(['error' => 'Valeur « archived » invalide.'], 422);
        }

        $this->conversationService->archive($user->id(), $conversationId, $archived);

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

    /** @return array<string, mixed> */
    private function summaryToArray(ConversationSummary $summary, int $userId): array
    {
        $mine = $summary->myLastMessage();

        return [
            'id' => $summary->conversation()->id(),
            'subject' => $summary->conversation()->subject(),
            'label' => $summary->label(),
            'unread' => $summary->isUnread(),
            'lastMessage' => $this->messageToArray($summary->lastMessage(), $userId),
            'myLastMessage' => $mine === null ? null : $this->messageToArray($mine, $userId),
        ];
    }

    /** @return array<string, mixed> */
    private function messageToArray(ConversationMessage $message, int $userId): array
    {
        return [
            'id' => $message->id(),
            'authorName' => $message->authorName(),
            'body' => $message->body(),
            'createdAt' => $message->createdAt()->format(\DATE_ATOM),
            'mine' => $message->authorId() === $userId,
        ];
    }
}
