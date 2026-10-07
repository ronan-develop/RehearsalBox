<?php

declare(strict_types=1);

namespace App\Messaging\Presenter;

use App\Messaging\Entity\ConversationMessage;
use App\View\TemplateRendererInterface;

/**
 * Fragments HTML des messages corrigés (#200) : le corps de la bulle, dessiné par le MÊME gabarit que la page, que le
 * navigateur substitue à l'ancien. Utilisé par l'édition (réponse à l'auteur) et par le polling (réponse aux autres).
 */
final class EditedMessageFragments
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly MessagesPageView $view,
    ) {
    }

    /**
     * @param list<ConversationMessage>      $messages
     * @param array<int, array<int, string>> $mentions identifiant du message => (identifiant de la personne => « @Nom »)
     *
     * @return list<array{id: int, html: string}>
     */
    public function fragments(array $messages, array $mentions, int $viewerId): array
    {
        return array_map(
            fn (array $row): array => ['id' => (int) $row['id'], 'html' => $this->renderer->render('messages/_message-body', ['row' => $row])],
            $this->view->editedRows($messages, $mentions, $viewerId),
        );
    }

    /** @param list<ConversationMessage> $messages Curseur à renvoyer au client : secondes Unix de la correction la plus récente (0 si aucune). */
    public static function cursor(array $messages, int $current = 0): int
    {
        $latest = $current;
        foreach ($messages as $message) {
            $latest = max($latest, $message->editedAt()?->getTimestamp() ?? 0);
        }

        return $latest;
    }
}
