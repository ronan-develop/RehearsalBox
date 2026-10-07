<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\FreeSlotBookingStatus;
use App\Entity\Enum\UserRole;
use App\Entity\FreeSlotBooking;
use App\Http\AfterResponseInterface;
use App\Mail\Mailbox;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Repository\Contract\UserRepositoryInterface;
use App\Service\Contract\BookingNotifierInterface;
use App\Support\FrenchDate;
use App\Support\HeaderText;
use Symfony\Component\Mime\Email;

/**
 * E-mails des réservations libres (#263). Envoyés APRÈS la réponse (la requête ne ralentit pas) et sans jamais la faire échouer :
 * un échec est journalisé avec des identifiants seulement, jamais une adresse. Pas de désabonnement : l'alerte aux
 * administrateurs est un devoir de gestion et l'issue concerne directement la personne ; le contenu ne porte ni adresse ni motif
 * du demandeur (seul le motif de refus, écrit par l'administrateur pour le demandeur, est repris).
 */
final class BookingNotifier implements BookingNotifierInterface
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly UserRepositoryInterface $users,
        private readonly GroupRepositoryInterface $groups,
        private readonly AfterResponseInterface $afterResponse,
    ) {
    }

    public function bookingRequested(FreeSlotBooking $booking): void
    {
        $this->afterResponse->defer(function () use ($booking): void {
            foreach ($this->users->findAll() as $user) {
                if ($user->role() === UserRole::Admin && $user->isActive()) {
                    $this->send($booking, $user->email(), 'booking-pending', 'Réservation à valider', [], '/admin/bookings');
                }
            }
        });
    }

    public function bookingDecided(FreeSlotBooking $booking): void
    {
        $this->afterResponse->defer(function () use ($booking): void {
            $user = $this->users->findById($booking->requester()->userId());
            if ($user === null || !$user->isActive()) {
                return;
            }
            $accepted = $booking->status() === FreeSlotBookingStatus::Validee;
            $this->send(
                $booking,
                $user->email(),
                'booking-decided',
                $accepted ? 'Réservation validée' : 'Réservation refusée',
                ['accepted' => $accepted, 'note' => $accepted ? null : $booking->decisionNote()],
                '/',
            );
        });
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function send(FreeSlotBooking $booking, string $to, string $template, string $subject, array $extra, string $path): void
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            error_log(sprintf('Notification de réservation : adresse invalide (réservation #%d).', $booking->id()));

            return;
        }
        try {
            $group = $this->groups->findById($booking->requester()->groupId());
            $when = FrenchDate::long($booking->date());
            $range = substr($booking->range()->start(), 0, 5) . ' – ' . substr($booking->range()->end(), 0, 5);
            $this->mailbox->send($this->mailbox->compose(
                $to,
                HeaderText::oneLine($subject),
                $template,
                $extra + [
                    'groupName' => $group?->name() ?? '',
                    'when' => $when,
                    'range' => $range,
                    'link' => $this->mailbox->url($path),
                    'preheader' => $subject . ' : ' . $when . ', ' . $range,
                ],
            ));
        } catch (\Throwable $e) {
            // Ni adresse ni contenu dans le journal : seulement des identifiants.
            error_log(sprintf('Notification de réservation : échec (%s, réservation #%d).', $e::class, $booking->id()));
        }
    }
}
