<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DueReminder;
use App\Mail\Mailbox;
use App\Repository\Contract\ConversationNoticeRepositoryInterface;
use App\Support\DaytimeWindow;
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
    public const MIN_AGE = '-24 hours';
    public const MAX_AGE = '-7 days';

    public function __construct(
        private readonly ConversationNoticeRepositoryInterface $notices,
        private readonly Mailbox $mailbox,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
    ) {
    }

    public function sendDue(): ReminderReport
    {
        $now = $this->clock->now();
        if (!DaytimeWindow::contains($now, $this->localTimezone)) {
            return new ReminderReport(outsideWindow: true);
        }

        $sent = $failed = $skipped = 0;
        foreach ($this->notices->findDueReminders($now->modify(self::MIN_AGE), $now->modify(self::MAX_AGE)) as $reminder) {
            if (filter_var($reminder->contactEmail(), FILTER_VALIDATE_EMAIL) === false) {
                error_log(sprintf('Relance de conversation : adresse de contact invalide (groupe #%d).', $reminder->groupId()));
                ++$skipped;
                continue;
            }

            $previous = $this->notices->remindedAt($reminder->conversationId(), $reminder->groupId());
            if (!$this->notices->claimReminder($reminder->conversationId(), $reminder->groupId(), $now)) {
                ++$skipped;
                continue;
            }

            try {
                $this->mailbox->send($this->buildMail($reminder));
                ++$sent;
            } catch (\Throwable $e) {
                // Rien n'est resté « relancé » : un nouvel essai reste possible. Ni adresse ni contenu dans le journal.
                $this->notices->restoreReminder($reminder->conversationId(), $reminder->groupId(), $previous);
                error_log(sprintf('Relance de conversation : échec (%s, conversation #%d, groupe #%d).', $e::class, $reminder->conversationId(), $reminder->groupId()));
                ++$failed;
            }
        }

        return new ReminderReport($sent, $failed, $skipped);
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
