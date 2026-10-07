<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DueMentionReminder;
use App\Mail\Mailbox;
use App\Repository\Contract\MentionNoticeRepositoryInterface;
use App\Support\HeaderText;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mime\Email;

/**
 * Relances de mention (#178), lancées par la même tâche horaire que celles des groupes (bin/send-reminders.php). Une personne
 * mentionnée qui n'a pas lu la conversation 24 h après l'e-mail de mention reçoit UNE relance, à l'adresse de son compte
 * (jamais le contenu), dans la plage de jour (heure locale). Au-delà de 7 jours, plus de relance. Un échec n'interrompt pas
 * les autres et laisse la relance réessayable.
 */
final class MentionReminderService
{
    public function __construct(
        private readonly MentionNoticeRepositoryInterface $notices,
        private readonly Mailbox $mailbox,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
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
                error_log(sprintf('Relance de mention : adresse invalide (personne #%d).', $reminder->userId()));
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
                error_log(sprintf('Relance de mention : échec (%s, conversation #%d, personne #%d).', $e::class, $reminder->conversationId(), $reminder->userId()));
                $run->markFailed();
            }
        }

        return $run->report();
    }

    private function buildMail(DueMentionReminder $reminder): Email
    {
        $name = $reminder->mentionerName() !== '' ? $reminder->mentionerName() : 'Quelqu\'un';

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
