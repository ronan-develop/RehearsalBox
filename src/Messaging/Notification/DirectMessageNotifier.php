<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use App\Account\Repository\UserRepositoryInterface;
use App\Mail\Mailbox;
use App\Messaging\Entity\Conversation;
use App\Messaging\Repository\Notice\MentionNoticeRepositoryInterface;
use App\Messaging\Repository\Participation\ConversationMuteRepositoryInterface;
use App\Support\HeaderText;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\Email;

/**
 * E-mail « X vous a écrit » d'un message direct (#269), envoyé à l'adresse du COMPTE de l'autre personne, jamais le texte ni
 * l'adresse de l'expéditeur. Mêmes garde-fous que les mentions (un e-mail par conversation et par 24 h, plafond par auteur,
 * réservation partagée avec elles) MAIS pas de désabonnement général : seule la sourdine de la conversation le coupe.
 * Un échec n'est jamais remonté à l'auteur : son message est déjà envoyé ; la réservation est annulée pour un nouvel essai.
 */
final class DirectMessageNotifier
{
    public const MAX_PER_AUTHOR_PER_HOUR = MentionNotifier::MAX_PER_AUTHOR_PER_HOUR;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly MentionNoticeRepositoryInterface $notices,
        private readonly UserRepositoryInterface $users,
        private readonly ConversationMuteRepositoryInterface $mutes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function newMessage(Conversation $conversation, int $authorId, string $authorName, \DateTimeImmutable $now): void
    {
        $recipientId = $conversation->otherParticipantOf($authorId);
        if ($recipientId === null) {
            return;
        }
        try {
            $this->notify($conversation, $authorId, $authorName, $recipientId, $now);
        } catch (\Throwable $e) {
            // Ni adresse ni contenu dans le journal : seulement des identifiants.
            $this->logger->error('E-mail de message direct : échec', ['exception' => $e::class, 'conversation' => $conversation->id(), 'user' => $recipientId]);
        }
    }

    private function notify(Conversation $conversation, int $authorId, string $authorName, int $recipientId, \DateTimeImmutable $now): void
    {
        $recipient = $this->users->findById($recipientId);
        if ($recipient === null || !$recipient->isActive() || $this->mutes->isMuted($conversation->id(), $recipientId)) {
            return;
        }
        if (filter_var($recipient->email(), FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('E-mail de message direct : adresse invalide', ['user' => $recipientId]);

            return;
        }
        if ($this->notices->countSentBy($authorId, $now->modify('-1 hour')) >= self::MAX_PER_AUTHOR_PER_HOUR) {
            $this->logger->warning('E-mail de message direct : plafond horaire atteint', ['author' => $authorId]);

            return;
        }

        $previous = $this->notices->find($conversation->id(), $recipientId);
        if (!$this->notices->claimNotice($conversation->id(), $recipientId, $authorId, $now, $now->modify(MentionNotifier::MIN_GAP))) {
            return;
        }

        try {
            $this->mailbox->send($this->buildMail($conversation, $authorName, $recipient->email()));
        } catch (\Throwable $e) {
            $this->notices->restoreNotice($conversation->id(), $recipientId, $previous);
            throw $e;
        }
    }

    private function buildMail(Conversation $conversation, string $authorName, string $to): Email
    {
        return $this->mailbox->compose(
            $to,
            HeaderText::oneLine($authorName) . ' vous a écrit',
            'direct-new',
            [
                'senderName' => $authorName,
                'link' => $this->mailbox->url('/messages/' . $conversation->id()),
                'preheader' => $authorName . ' vous a envoyé un message sur RehearsalBox.',
            ],
        );
    }
}
