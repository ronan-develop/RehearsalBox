<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use App\Messaging\Entity\DueMentionReminder;
use App\Mail\Mailbox;
use App\Messaging\Repository\Notice\MentionNoticeRepositoryInterface;
use App\Support\HeaderText;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mime\Email;

/**
 * Relances de mention (#178), lancées par la même tâche horaire que celles des groupes (bin/send-reminders.php). Une personne
 * mentionnée qui n'a pas lu la conversation 24 h après l'e-mail de mention reçoit UNE relance, à l'adresse de son compte
 * (jamais le contenu), dans la plage de jour (heure locale). Au-delà de 7 jours, plus de relance. Un échec n'interrompt pas
 * les autres et laisse la relance réessayable.
 *
 * Mêmes relances pour un message direct (#372), dans le même cycle, avec un autre texte (« vous a écrit ») et sans lien de
 * désabonnement général : seule la sourdine de la conversation coupe les e-mails d'un message direct.
 */
final class MentionReminderService
{
    public function __construct(
        private readonly MentionNoticeRepositoryInterface $notices,
        private readonly Mailbox $mailbox,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function sendDue(): ReminderReport
    {
        $run = new ReminderRun($this->clock->now(), $this->localTimezone);
        if (!$run->inDaytime()) {
            return $run->outsideWindowReport();
        }

        $now = $run->now();
        foreach ($this->notices->findDueReminders($run->notBefore(), $run->notAfter()) as $reminder) {
            if (filter_var($reminder->email(), FILTER_VALIDATE_EMAIL) === false) {
                $this->logger->warning('Relance de mention : adresse invalide', ['user' => $reminder->userId()]);
                $run->markSkipped();
                continue;
            }
            if (!$this->notices->claimReminder($reminder->conversationId(), $reminder->userId(), $now)) {
                $run->markSkipped();
                continue;
            }

            try {
                $this->mailbox->send($this->buildMail($reminder));
                $run->markSent();
            } catch (\Throwable $e) {
                $this->notices->restoreReminder($reminder->conversationId(), $reminder->userId());
                $this->logger->error('Relance de mention : échec', ['exception' => $e::class, 'conversation' => $reminder->conversationId(), 'user' => $reminder->userId()]);
                $run->markFailed();
            }
        }

        return $run->report();
    }

    private function buildMail(DueMentionReminder $reminder): Email
    {
        $name = $reminder->mentionerName() !== '' ? $reminder->mentionerName() : 'Quelqu\'un';
        if ($reminder->isDirect()) {
            return $this->mailbox->compose(
                $reminder->email(),
                'rappel : ' . HeaderText::oneLine($name) . ' vous a écrit',
                'direct-reminder',
                [
                    'senderName' => $name,
                    'link' => $this->mailbox->url('/messages/' . $reminder->conversationId()),
                    'preheader' => $name . ' vous a écrit et le message n\'a pas encore été lu.',
                ],
            );
        }

        return $this->mailbox->compose(
            $reminder->email(),
            'rappel : ' . HeaderText::oneLine($name) . ' vous a mentionné(e)',
            'mention-reminder',
            [
                'mentionerName' => $name,
                'link' => $this->mailbox->url('/messages/' . $reminder->conversationId()),
                'accountLink' => $this->mailbox->url('/account/password'),
                'preheader' => 'Vous avez été mentionné(e) et le message n\'a pas encore été lu.',
            ],
        );
    }
}
