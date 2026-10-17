<?php

declare(strict_types=1);

namespace App\Accounting\Notification;

use App\Account\Repository\UserRepositoryInterface;
use App\Accounting\Repository\Notice\ReminderNoticeRepositoryInterface;
use App\Accounting\Todo\TodoItem;
use App\Accounting\Todo\TodoService;
use App\Accounting\Todo\TodoState;
use App\Assembly\Entity\BoardPost;
use App\Association\Repository\AssociationBoardRepositoryInterface;
use App\Mail\Mailbox;
use App\Messaging\Notification\ReminderReport;
use App\Support\DaytimeWindow;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\ClockInterface;

/**
 * Rappel par e-mail des tâches de comptabilité en retard (#362), lancé par la même tâche horaire que les autres relances (bin/send-reminders.php).
 * Destinataire : le COMPTE titulaire du poste de trésorier (page du bureau) ; poste vide, compte désactivé ou adresse invalide : aucun e-mail. UN
 * seul message récapitulatif, au plus un par `intervalDays` même si le cron tourne toutes les heures (réservation atomique, libérée si l'envoi
 * échoue), et il s'arrête dès que plus rien n'est en retard. Au bout de `presidentAfterDays` jours de retard, le président reçoit lui aussi son
 * message (sauf s'il est aussi le trésorier). Dans la plage de jour seulement. Aucun montant, aucun nom, aucune donnée bancaire dans le message :
 * un nombre de tâches et un lien. Désactivé par défaut (réglage `accounting.reminders.enabled`).
 */
final class TreasurerReminderService
{
    public function __construct(
        private readonly TodoService $todo,
        private readonly AssociationBoardRepositoryInterface $board,
        private readonly UserRepositoryInterface $users,
        private readonly ReminderNoticeRepositoryInterface $notices,
        private readonly Mailbox $mailbox,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $localTimezone,
        private readonly bool $enabled,
        private readonly int $intervalDays = 7,
        private readonly int $presidentAfterDays = 14,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function sendDue(): ReminderReport
    {
        if (!$this->enabled) {
            return new ReminderReport();
        }
        $now = $this->clock->now();
        if (!DaytimeWindow::contains($now, $this->localTimezone)) {
            return new ReminderReport(outsideWindow: true);
        }
        $today = new \DateTimeImmutable($now->setTimezone($this->localTimezone)->format('Y-m-d'));
        $late = array_values(array_filter($this->todo->forReminder($today), static fn (TodoItem $i): bool => $i->state === TodoState::EnRetard));
        if ($late === []) {
            return new ReminderReport();
        }

        $sent = $failed = $skipped = 0;
        $holders = $this->board->all();
        $treasurerId = ($holders[BoardPost::Tresorier->value] ?? null)?->userId;
        $plans = [['treasurer', BoardPost::Tresorier, 'accounting-reminder', 'rappel : ' . count($late) . ' tâche(s) de comptabilité en retard', 0]];
        $oldest = $this->oldestLateDays($late, $today);
        if ($oldest > $this->presidentAfterDays) {
            $plans[] = ['president', BoardPost::President, 'accounting-reminder-president', 'rappel : comptabilité en retard depuis plus de ' . $this->presidentAfterDays . ' jours', $this->presidentAfterDays];
        }
        foreach ($plans as [$channel, $post, $template, $subject, $days]) {
            $holder = $holders[$post->value] ?? null;
            $holderId = $holder === null ? null : $holder->userId;
            $user = $holderId === null ? null : $this->users->findById($holderId);
            if ($user === null || !$user->isActive() || filter_var($user->email(), FILTER_VALIDATE_EMAIL) === false || ($channel === 'president' && $holderId === $treasurerId)) {
                $this->logger->info('Rappel comptabilité : aucun destinataire', ['channel' => $channel]);
                ++$skipped;
                continue;
            }
            $previous = $this->notices->claim($channel, $now, $now->modify('-' . $this->intervalDays . ' days'));
            if ($previous === null) {
                ++$skipped;
                continue;
            }
            try {
                $this->mailbox->send($this->mailbox->compose($user->email(), $subject, $template, ['count' => count($late), 'days' => $days, 'link' => $this->mailbox->url('/admin/accounting')]));
                ++$sent;
            } catch (\Throwable $e) {
                $this->notices->release($channel, $now, $previous);
                $this->logger->error('Rappel comptabilité : échec', ['exception' => $e::class, 'channel' => $channel]);
                ++$failed;
            }
        }

        return new ReminderReport($sent, $failed, $skipped);
    }

    /** Pourquoi les rappels activés ne partent pas : le poste de trésorier est vide ou son compte est désactivé (bandeau de la page), sinon null. */
    public function recipientProblem(): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        $holder = $this->board->all()[BoardPost::Tresorier->value] ?? null;
        if ($holder === null) {
            return 'Le poste de trésorier est vide : aucun rappel ne part.';
        }
        $user = $this->users->findById($holder->userId);
        if ($user === null || !$user->isActive()) {
            return 'Le compte du trésorier est désactivé : aucun rappel ne part.';
        }

        return null;
    }

    /** @param list<TodoItem> $late */
    private function oldestLateDays(array $late, \DateTimeImmutable $today): int
    {
        $days = 0;
        foreach ($late as $item) {
            if ($item->dueOn !== null) {
                $days = max($days, (int) $item->dueOn->diff($today)->format('%r%a'));
            }
        }

        return $days;
    }
}
