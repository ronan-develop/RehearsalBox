<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DueMentionReminder;
use App\Mail\MailRenderer;
use App\Repository\Contract\MentionNoticeRepositoryInterface;
use App\Support\DaytimeWindow;
use App\Support\HeaderText;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Relances de mention (#178), lancées par la même tâche horaire que celles des groupes (bin/send-reminders.php). Une personne
 * mentionnée qui n'a pas lu la conversation 24 h après l'e-mail de mention reçoit UNE relance, à l'adresse de son compte
 * (jamais le contenu), dans la plage de jour (heure locale). Au-delà de 7 jours, plus de relance. Un échec n'interrompt pas
 * les autres et laisse la relance réessayable.
 */
final class MentionReminderService
{
    public const MIN_AGE = '-24 hours';
    public const MAX_AGE = '-7 days';

    public function __construct(
        private readonly MentionNoticeRepositoryInterface $notices,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly ?MailRenderer $mailRenderer = null,
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
            if (filter_var($reminder->email(), FILTER_VALIDATE_EMAIL) === false) {
                error_log(sprintf('Relance de mention : adresse invalide (personne #%d).', $reminder->userId()));
                ++$skipped;
                continue;
            }
            if (!$this->notices->claimReminder($reminder->conversationId(), $reminder->userId(), $now)) {
                ++$skipped;
                continue;
            }

            try {
                $this->mailer->send($this->buildMail($reminder));
                ++$sent;
            } catch (\Throwable $e) {
                $this->notices->restoreReminder($reminder->conversationId(), $reminder->userId());
                error_log(sprintf('Relance de mention : échec (%s, conversation #%d, personne #%d).', $e::class, $reminder->conversationId(), $reminder->userId()));
                ++$failed;
            }
        }

        return new ReminderReport($sent, $failed, $skipped);
    }

    private function buildMail(DueMentionReminder $reminder): Email
    {
        $base = rtrim($this->baseUrl, '/');
        $name = $reminder->mentionerName() !== '' ? $reminder->mentionerName() : 'Quelqu\'un';

        return ($this->mailRenderer ?? MailRenderer::withDefaultTemplates())->compose(
            (new Email())
                ->from($this->fromAddress)
                ->to($reminder->email())
                ->subject('RehearsalBox — rappel : ' . HeaderText::oneLine($name) . ' vous a mentionné(e)'),
            'mention-reminder',
            [
                'mentionerName' => $name,
                'link' => $base . '/messages/' . $reminder->conversationId(),
                'accountLink' => $base . '/account/password',
                'preheader' => 'Vous avez été mentionné(e) et le message n\'a pas encore été lu.',
            ],
        );
    }
}
