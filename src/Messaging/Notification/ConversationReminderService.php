<?php

declare(strict_types=1);

namespace App\Messaging\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use App\Messaging\Entity\DueReminder;
use App\Mail\Mailbox;
use App\Messaging\Repository\Notice\ConversationNoticeRepositoryInterface;
use App\Support\HeaderText;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mime\Email;

/**
 * Relances de la messagerie (#180), lancées toutes les heures par une tâche planifiée (bin/send-reminders.php). Quand un message
 * de l'autre côté est resté sans lecture 24 h par le groupe (personne du groupe ne l'a ouvert), l'adresse de contact du groupe
 * reçoit UNE relance (jamais le contenu du message). Seulement dans la plage de jour (heure locale) : celles qui tombent la nuit
 * partent le matin. Un message de plus de 7 jours n'est plus relancé (évite une relance tardive après une interruption).
 * Un échec d'envoi n'interrompt pas les autres et laisse la relance réessayable.
 */
final class ConversationReminderService
{
    public function __construct(
        private readonly ConversationNoticeRepositoryInterface $notices,
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
            if (filter_var($reminder->contactEmail(), FILTER_VALIDATE_EMAIL) === false) {
                $this->logger->warning('Relance de conversation : adresse de contact invalide', ['group' => $reminder->groupId()]);
                $run->markSkipped();
                continue;
            }

            $previous = $this->notices->remindedAt($reminder->conversationId(), $reminder->groupId());
            if (!$this->notices->claimReminder($reminder->conversationId(), $reminder->groupId(), $now)) {
                $run->markSkipped();
                continue;
            }

            try {
                $this->mailbox->send($this->buildMail($reminder));
                $run->markSent();
            } catch (\Throwable $e) {
                // Rien n'est resté « relancé » : un nouvel essai reste possible. Ni adresse ni contenu dans le journal.
                $this->notices->restoreReminder($reminder->conversationId(), $reminder->groupId(), $previous);
                $this->logger->error('Relance de conversation : échec', ['exception' => $e::class, 'conversation' => $reminder->conversationId(), 'group' => $reminder->groupId()]);
                $run->markFailed();
            }
        }

        return $run->report();
    }

    private function buildMail(DueReminder $reminder): Email
    {
        return $this->mailbox->compose(
            $reminder->contactEmail(),
            'rappel : un message de ' . HeaderText::oneLine($reminder->counterpartName()) . ' attend une réponse',
            'conversation-reminder',
            [
                'counterpartName' => $reminder->counterpartName(),
                'link' => $this->mailbox->url('/messages/' . $reminder->conversationId()),
                'preheader' => 'Un message de ' . $reminder->counterpartName() . ' n\'a pas encore été lu.',
            ],
        );
    }
}
