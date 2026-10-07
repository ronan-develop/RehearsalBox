<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use App\Messaging\Entity\Conversation;
use App\Group\Entity\Group;
use App\Mail\Mailbox;
use App\Messaging\Repository\Notice\ConversationNoticeRepositoryInterface;
use App\Support\HeaderText;
use Symfony\Component\Mime\Email;
use App\Messaging\Notification\NewConversationNotifierInterface;

/**
 * E-mails de la messagerie (#180). Le premier message prévient le groupe visé UNE SEULE FOIS, à l'adresse de contact de sa
 * fiche (pas un e-mail par membre) : le groupe fait ensuite intervenir les siens en les mentionnant. L'e-mail ne contient ni
 * le titre ni le texte du message (saisis par un utilisateur, bientôt chiffrés) : seulement qui écrit, au nom de quel groupe,
 * et un lien vers la conversation (connexion requise). Un échec d'envoi ne remonte jamais : le message est déjà envoyé.
 */
final class ConversationNotifier implements NewConversationNotifierInterface
{
    /** Plafond par groupe visé et par 24 h, quel que soit l'expéditeur : l'adresse de contact d'un tiers n'est pas un canal à saturer. */
    public const MAX_NEW_CONVERSATIONS_PER_DAY = 5;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly ConversationNoticeRepositoryInterface $notices,
    ) {
    }

    public function newConversation(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void
    {
        try {
            $this->notify($conversation, $authorName, $authorGroupName, $targetGroup, $now);
        } catch (\Throwable $e) {
            // Le message est déjà envoyé : AUCUNE panne de notification (base, gabarit, transport) ne doit l'annuler ni
            // remonter à l'utilisateur. Ni adresse ni contenu dans le journal.
            error_log(sprintf('Notification de conversation : échec (%s, conversation #%d, groupe #%d).', $e::class, $conversation->id(), $targetGroup->id()));
        }
    }

    private function notify(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void
    {
        if (filter_var($targetGroup->contactEmail(), FILTER_VALIDATE_EMAIL) === false) {
            error_log(sprintf('Notification de conversation : adresse de contact invalide (groupe #%d).', $targetGroup->id()));

            return;
        }
        if (!$this->notices->claimInitial($conversation->id(), $targetGroup->id(), $now)) {
            return;
        }
        if ($this->notices->countInitialSince($targetGroup->id(), $now->modify('-24 hours')) > self::MAX_NEW_CONVERSATIONS_PER_DAY) {
            // Le groupe a déjà reçu son quota : la conversation existe, seul l'e-mail est retenu (sans réessai).
            $this->notices->releaseInitial($conversation->id(), $targetGroup->id());
            error_log(sprintf('Notification de conversation : plafond quotidien atteint (groupe #%d).', $targetGroup->id()));

            return;
        }

        try {
            $this->mailbox->send($this->buildMail($conversation, $authorName, $authorGroupName, $targetGroup->contactEmail()));
        } catch (\Throwable $e) {
            // Rien n'est resté « envoyé » : un nouvel essai reste possible.
            $this->notices->releaseInitial($conversation->id(), $targetGroup->id());
            throw $e;
        }
    }

    private function buildMail(Conversation $conversation, string $authorName, string $authorGroupName, string $to): Email
    {
        return $this->mailbox->compose(
            $to,
            'nouvelle conversation de ' . HeaderText::oneLine($authorGroupName),
            'conversation-new',
            [
                'authorName' => $authorName,
                'groupName' => $authorGroupName,
                'link' => $this->mailbox->url('/messages/' . $conversation->id()),
                'preheader' => $authorName . ' vous a écrit.',
            ],
        );
    }
}
