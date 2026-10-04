<?php

declare(strict_types=1);

namespace App\Service;

use App\Mail\MailRenderer;
use App\Repository\Contract\GroupRepositoryInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class GroupContactService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly string $fromAddress,
        private readonly ?MailRenderer $mailRenderer = null,
    ) {
    }

    public function send(int $groupId, int $senderUserId, string $senderEmail, string $message): void
    {
        $group = $this->groupRepository->findById($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException("Groupe {$groupId} introuvable.");
        }

        $mail = ($this->mailRenderer ?? MailRenderer::withDefaultTemplates())->render('group-contact', [
            'senderEmail' => $senderEmail,
            'message' => $message,
            'preheader' => 'Un musicien vous a écrit depuis RehearsalBox.',
        ]);

        $email = (new Email())
            ->from($this->fromAddress)
            ->to($group->contactEmail())
            ->replyTo($senderEmail)
            ->subject('RehearsalBox — demande de contact')
            ->html($mail['html'])
            ->text($mail['text']);

        $this->mailer->send($email);
    }
}
