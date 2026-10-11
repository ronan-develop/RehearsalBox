<?php

declare(strict_types=1);

namespace App\Account\Service\Announcement;

use App\Account\Repository\UserRepositoryInterface;
use App\Mail\Mailbox;
use Symfony\Component\Mime\Email;

/**
 * E-mail d'annonce « l'appli est ouverte » (#444) : une fois, à chaque compte actif. Trois gestes, du plus sûr au plus engageant :
 * simulation (rien ne part), essai à UNE adresse (sans mémoire), envoi réel (chaque compte servi est noté : relancer ne crée aucun doublon).
 */
final class AnnouncementService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly Mailbox $mailbox,
        private readonly AnnouncementLedger $ledger,
    ) {
    }

    public function dryRun(): AnnouncementReport
    {
        [$todo, $done] = $this->split();

        return new AnnouncementReport(0, 0, count($done), count($todo));
    }

    public function sendToAll(): AnnouncementReport
    {
        [$todo, $done] = $this->split();
        $sent = $failed = 0;
        foreach ($todo as $user) {
            if ($this->mailbox->sendSafely(fn (): Email => $this->build($user->email()), "annonce, compte #{$user->id()}")) {
                $this->ledger->add($user->id());
                ++$sent;
            } else {
                ++$failed;
            }
        }

        return new AnnouncementReport($sent, $failed, count($done), count($todo));
    }

    public function sendTest(string $to): AnnouncementReport
    {
        $ok = $this->mailbox->sendSafely(fn (): Email => $this->build($to), 'annonce, essai');

        return new AnnouncementReport($ok ? 1 : 0, $ok ? 0 : 1, 0, 1);
    }

    /** @return array{0: list<\App\Account\Entity\User>, 1: list<\App\Account\Entity\User>} comptes actifs à servir, comptes déjà servis */
    private function split(): array
    {
        $todo = $done = [];
        foreach ($this->users->findAll() as $user) {
            if (!$user->isActive()) {
                continue;
            }
            if ($this->ledger->has($user->id())) {
                $done[] = $user;
            } else {
                $todo[] = $user;
            }
        }

        return [$todo, $done];
    }

    private function build(string $to): Email
    {
        return $this->mailbox->compose($to, "l'appli #B27 est ouverte", 'announcement', [
            'link' => $this->mailbox->url('/login'),
            'forgotLink' => $this->mailbox->url('/forgot-password'),
            'preheader' => 'Voir à l’avance si le local est libre : mode d’emploi en 4 points.',
        ]);
    }
}
