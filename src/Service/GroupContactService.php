<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contract\GroupRepositoryInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class GroupContactService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GroupRepositoryInterface $groupRepository,
    ) {
    }

    public function send(int $groupId, int $senderUserId, string $senderEmail, string $message): void
    {
        $group = $this->groupRepository->findById($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException("Groupe {$groupId} introuvable.");
        }

        $email = (new Email())
            ->from($senderEmail)
            ->to($group->contactEmail())
            ->replyTo($senderEmail)
            ->subject('RehearsalBox — demande de contact')
            ->text($message);

        $this->mailer->send($email);
    }
}
