<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Conversation;
use App\Entity\Group;
use App\Mail\MailRenderer;
use App\Repository\Contract\ConversationNoticeRepositoryInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * E-mails de la messagerie (#180). Le premier message prévient le groupe visé UNE SEULE FOIS, à l'adresse de contact de sa
 * fiche (pas un e-mail par membre) : le groupe fait ensuite intervenir les siens en les mentionnant. L'e-mail ne contient ni
 * le titre ni le texte du message (saisis par un utilisateur, bientôt chiffrés) : seulement qui écrit, au nom de quel groupe,
 * et un lien vers la conversation (connexion requise). Un échec d'envoi ne remonte jamais : le message est déjà envoyé.
 */
final class ConversationNotifier
{
    private const MAX_SUBJECT_NAME_LENGTH = 100;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly ConversationNoticeRepositoryInterface $notices,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly ?MailRenderer $mailRenderer = null,
    ) {
    }

    public function newConversation(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void
    {
        try {
            $this->notify($conversation, $authorName, $authorGroupName, $targetGroup, $now);
        } catch (\Throwable $e) {
            // Le message est déjà envoyé : AUCUNE panne de notification (base, gabarit, transport) ne doit l'annuler ni
            // remonter à l'utilisateur. Ni adresse ni contenu dans le journal.
            error_log(sprintf('Notification de conversation : échec (%s, conversation #%d, groupe #%d).', $e::class, $conversation->id(), $targetGroup->id()));
        }
    }

    private function notify(Conversation $conversation, string $authorName, string $authorGroupName, Group $targetGroup, \DateTimeImmutable $now): void
    {
        if (filter_var($targetGroup->contactEmail(), FILTER_VALIDATE_EMAIL) === false) {
            error_log(sprintf('Notification de conversation : adresse de contact invalide (groupe #%d).', $targetGroup->id()));

            return;
        }
        if (!$this->notices->claimInitial($conversation->id(), $targetGroup->id(), $now)) {
            return;
        }

        try {
            $this->mailer->send($this->buildMail($conversation, $authorName, $authorGroupName, $targetGroup->contactEmail()));
        } catch (\Throwable $e) {
            // Rien n'est resté « envoyé » : un nouvel essai reste possible.
            $this->notices->releaseInitial($conversation->id(), $targetGroup->id());
            throw $e;
        }
    }

    private function buildMail(Conversation $conversation, string $authorName, string $authorGroupName, string $to): Email
    {
        return ($this->mailRenderer ?? MailRenderer::withDefaultTemplates())->compose(
            (new Email())
                ->from($this->fromAddress)
                ->to($to)
                ->subject('RehearsalBox — nouvelle conversation de ' . $this->oneLine($authorGroupName)),
            'conversation-new',
            [
                'authorName' => $authorName,
                'groupName' => $authorGroupName,
                'link' => rtrim($this->baseUrl, '/') . '/messages/' . $conversation->id(),
                'preheader' => $authorName . ' vous a écrit.',
            ],
        );
    }

    /** Un nom de groupe dans un objet : une seule ligne, sans caractère de contrôle ni de mise en forme (injection d'en-tête). */
    private function oneLine(string $text): string
    {
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text));

        return mb_substr($clean, 0, self::MAX_SUBJECT_NAME_LENGTH);
    }
}
