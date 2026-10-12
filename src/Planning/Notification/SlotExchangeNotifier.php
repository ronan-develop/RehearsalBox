<?php

declare(strict_types=1);

namespace App\Planning\Notification;

use App\Account\Repository\UserRepositoryInterface;
use App\Group\Repository\GroupRepositoryInterface;
use App\Http\AfterResponseInterface;
use App\Mail\Mailbox;
use App\Planning\Entity\SlotException;
use App\Planning\Entity\SlotExceptionStatus;
use App\Planning\Repository\RecurringSlotRepositoryInterface;
use App\Support\FrenchDate;
use App\Support\HeaderText;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * E-mails des demandes d'échange de créneau (#455). Envoyés APRÈS la réponse et sans jamais la faire échouer : un échec est journalisé
 * avec des identifiants seulement. La demande va à l'adresse de CONTACT du groupe titulaire (comme les conversations entre groupes),
 * la réponse à la personne qui a demandé. Le motif écrit par un groupe pour un autre n'est jamais repris dans un e-mail.
 */
final class SlotExchangeNotifier implements SlotExchangeNotifierInterface
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly GroupRepositoryInterface $groups,
        private readonly RecurringSlotRepositoryInterface $slots,
        private readonly UserRepositoryInterface $users,
        private readonly AfterResponseInterface $afterResponse,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function requested(SlotException $exception): void
    {
        $this->afterResponse->defer(fn () => $this->notify($exception, true));
    }

    public function decided(SlotException $exception): void
    {
        $this->afterResponse->defer(fn () => $this->notify($exception, false));
    }

    private function notify(SlotException $exception, bool $isRequest): void
    {
        try {
            $slot = $this->slots->findById($exception->recurringSlotId());
            $holder = $slot === null ? null : $this->groups->findById($slot->groupId());
            $requester = $this->groups->findById($exception->requester()->groupId());
            if ($slot === null || $holder === null || $requester === null) {
                return;
            }

            $accepted = $exception->status() === SlotExceptionStatus::Acceptee;
            if ($isRequest) {
                $to = $holder->contactEmail();
                $subject = "demande d'échange de créneau de " . HeaderText::oneLine($requester->name());
            } else {
                $user = $this->users->findById($exception->requester()->userId());
                $to = $user !== null && $user->isActive() ? $user->email() : '';
                $subject = 'échange de créneau ' . ($accepted ? 'accepté' : 'refusé') . ' par ' . HeaderText::oneLine($holder->name());
            }
            if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
                $this->logger->warning("Notification d'échange de créneau : adresse invalide", ['exchange' => $exception->id()]);

                return;
            }

            $when = FrenchDate::long($exception->occurrenceDate());
            $range = substr($exception->range()?->start() ?? $slot->startTime(), 0, 5) . ' – ' . substr($exception->range()?->end() ?? $slot->endTime(), 0, 5);
            $this->mailbox->send($this->mailbox->compose($to, $subject, $isRequest ? 'exchange-requested' : 'exchange-decided', [
                'requesterGroup' => $requester->name(),
                'holderGroup' => $holder->name(),
                'accepted' => $accepted,
                'when' => $when,
                'range' => $range,
                'link' => $this->mailbox->url('/'),
                'preheader' => $when . ', ' . $range,
            ]));
        } catch (\Throwable $e) {
            // Ni adresse ni contenu dans le journal : seulement des identifiants.
            $this->logger->error("Notification d'échange de créneau : échec", ['exception' => $e::class, 'exchange' => $exception->id()]);
        }
    }
}
