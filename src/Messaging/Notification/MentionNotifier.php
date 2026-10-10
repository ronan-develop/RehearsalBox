<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use App\Messaging\Entity\Conversation;
use App\Messaging\Entity\MentionNotice;
use App\Mail\Mailbox;
use App\Messaging\Repository\Notice\MentionNoticeRepositoryInterface;
use App\Messaging\Repository\Participation\ConversationMuteRepositoryInterface;
use App\Account\Repository\UserRepositoryInterface;
use App\Support\HeaderText;
use Symfony\Component\Mime\Email;
use App\Messaging\Service\Mention\ConversationMentionService;

/**
 * E-mail « vous avez été mentionné(e) » (#178), envoyé à l'adresse du COMPTE de la personne taguée, jamais le contenu ni
 * le titre. Règles : un e-mail seulement s'il n'en est pas déjà parti un pour cette personne dans cette conversation depuis
 * 24 h (dix tags en une heure = un e-mail), personne désinscrite, inactive ou sans adresse valide ignorée, au plus
 * MAX_PER_AUTHOR_PER_HOUR e-mails par auteur et par heure. Un échec n'est jamais remonté à l'auteur : son message est déjà
 * envoyé ; la réservation est annulée pour qu'un nouvel essai reste possible.
 */
final class MentionNotifier
{
    public const MIN_GAP = '-24 hours';
    public const MAX_PER_AUTHOR_PER_HOUR = 10;

    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly MentionNoticeRepositoryInterface $notices,
        private readonly UserRepositoryInterface $users,
        private readonly ConversationMuteRepositoryInterface $mutes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** @param list<int> $mentionedUserIds personnes taguées dans le message (déjà validées par ConversationMentionService) */
    public function mentioned(Conversation $conversation, int $authorId, string $authorName, array $mentionedUserIds, \DateTimeImmutable $now): void
    {
        foreach ($mentionedUserIds as $userId) {
            if ($userId === $authorId) {
                continue;
            }
            try {
                $this->notify($conversation, $authorId, $authorName, $userId, $now);
            } catch (\Throwable $e) {
                // Ni adresse ni contenu dans le journal : seulement des identifiants.
                $this->logger->error('E-mail de mention : échec', ['exception' => $e::class, 'conversation' => $conversation->id(), 'user' => $userId]);
            }
        }
    }

    private function notify(Conversation $conversation, int $authorId, string $authorName, int $userId, \DateTimeImmutable $now): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->isActive()) {
            return;
        }
        // Sourdine (#210) : aucune mention de cette conversation n'envoie d'e-mail, et rien n'est réservé (levée = reprise).
        if ($this->mutes->isMuted($conversation->id(), $userId)) {
            return;
        }
        if (filter_var($user->email(), FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('E-mail de mention : adresse invalide', ['user' => $userId]);

            return;
        }
        if ($this->notices->countSentBy($authorId, $now->modify('-1 hour')) >= self::MAX_PER_AUTHOR_PER_HOUR) {
            $this->logger->warning('E-mail de mention : plafond horaire atteint', ['author' => $authorId]);

            return;
        }

        $previous = $this->notices->find($conversation->id(), $userId);
        if (!$this->notices->claimNotice($conversation->id(), $userId, $authorId, $now, $now->modify(self::MIN_GAP))) {
            return;
        }

        try {
            $this->mailbox->send($this->buildMail($conversation, $authorName, $user->email()));
        } catch (\Throwable $e) {
            $this->notices->restoreNotice($conversation->id(), $userId, $previous);
            throw $e;
        }
    }

    private function buildMail(Conversation $conversation, string $authorName, string $to): Email
    {
        return $this->mailbox->compose(
            $to,
            HeaderText::oneLine($authorName) . ' vous a mentionné(e)',
            'mention-new',
            [
                'mentionerName' => $authorName,
                'link' => $this->mailbox->url('/messages/' . $conversation->id()),
                'preheader' => $authorName . ' vous a mentionné(e) dans une conversation.',
            ],
        );
    }
}
