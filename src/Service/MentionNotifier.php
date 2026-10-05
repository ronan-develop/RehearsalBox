<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\MentionNotice;
use App\Mail\MailRenderer;
use App\Repository\Contract\MentionNoticeRepositoryInterface;
use App\Repository\Contract\NotificationPreferenceRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Support\HeaderText;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

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
        private readonly MailerInterface $mailer,
        private readonly MentionNoticeRepositoryInterface $notices,
        private readonly UserRepositoryInterface $users,
        private readonly NotificationPreferenceRepositoryInterface $preferences,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly ?MailRenderer $mailRenderer = null,
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
                error_log(sprintf('E-mail de mention : échec (%s, conversation #%d, personne #%d).', $e::class, $conversation->id(), $userId));
            }
        }
    }

    private function notify(Conversation $conversation, int $authorId, string $authorName, int $userId, \DateTimeImmutable $now): void
    {
        $user = $this->users->findById($userId);
        if ($user === null || !$user->isActive() || !$this->preferences->emailEnabled($userId)) {
            return;
        }
        if (filter_var($user->email(), FILTER_VALIDATE_EMAIL) === false) {
            error_log(sprintf('E-mail de mention : adresse invalide (personne #%d).', $userId));

            return;
        }
        if ($this->notices->countSentBy($authorId, $now->modify('-1 hour')) >= self::MAX_PER_AUTHOR_PER_HOUR) {
            error_log(sprintf('E-mail de mention : plafond horaire atteint (auteur #%d).', $authorId));

            return;
        }

        $previous = $this->notices->find($conversation->id(), $userId);
        if (!$this->notices->claimNotice($conversation->id(), $userId, $authorId, $now, $now->modify(self::MIN_GAP))) {
            return;
        }

        try {
            $this->mailer->send($this->buildMail($conversation, $authorName, $user->email()));
        } catch (\Throwable $e) {
            $this->notices->restoreNotice($conversation->id(), $userId, $previous);
            throw $e;
        }
    }

    private function buildMail(Conversation $conversation, string $authorName, string $to): Email
    {
        $base = rtrim($this->baseUrl, '/');

        return ($this->mailRenderer ?? MailRenderer::withDefaultTemplates())->compose(
            (new Email())
                ->from($this->fromAddress)
                ->to($to)
                ->subject('RehearsalBox — ' . HeaderText::oneLine($authorName) . ' vous a mentionné(e)'),
            'mention-new',
            [
                'mentionerName' => $authorName,
                'link' => $base . '/messages/' . $conversation->id(),
                'accountLink' => $base . '/account/password',
                'preheader' => $authorName . ' vous a mentionné(e) dans une conversation.',
            ],
        );
    }
}
