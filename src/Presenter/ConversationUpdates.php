<?php

declare(strict_types=1);

namespace App\Presenter;

use App\Service\ConversationReader;
use App\View\TemplateRendererInterface;

/**
 * Charge utile d'une mise à jour de fil (polling et réponse à l'envoi) : les messages plus récents que `after`, déjà dessinés
 * par le serveur (même gabarit que la page), avec qui écrit, « vu par », le titre et les corrections reçues. Recevoir en
 * direct un message des autres vaut lecture.
 */
final class ConversationUpdates
{
    public function __construct(
        private readonly ConversationReader $reader,
        private readonly MessagesPageView $view,
        private readonly TemplateRendererInterface $renderer,
        private readonly EditedMessageFragments $editedFragments,
    ) {
    }

    /** @return array<string, mixed> */
    public function payload(int $userId, int $conversationId, int $after, ?int $editedAfter = null): array
    {
        $view = $this->view->thread($this->reader->poll($userId, $conversationId, $after), $userId);
        $edited = $editedAfter === null
            ? ['messages' => [], 'mentions' => []]
            : $this->reader->edited($userId, $conversationId, (new \DateTimeImmutable())->setTimestamp($editedAfter), $after);

        return [
            'edited' => $this->editedFragments->fragments($edited['messages'], $edited['mentions'], $userId),
            'editedAt' => EditedMessageFragments::cursor($edited['messages'], $editedAfter ?? 0),
            'html' => $this->renderer->render('messages/_rows', ['rows' => $view['rows']]),
            'lastId' => $view['lastId'],
            'hasNew' => $view['rows'] !== [],
            'status' => $view['status'],
            'typing' => $view['typing'],
            'title' => $view['title'],
            'displayTitle' => $view['displayTitle'],
            'label' => $view['label'],
        ];
    }
}
