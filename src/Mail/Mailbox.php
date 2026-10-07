<?php

declare(strict_types=1);

namespace App\Mail;

use App\View\PhpTemplateRenderer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Tout ce qui voyage ensemble pour envoyer un e-mail du site (#237) : le transport, l'adresse d'expédition, l'URL de base du site
 * (pour les liens) et le rendu des gabarits. Les services reçoivent une Mailbox au lieu de répéter ces quatre paramètres.
 * Volontairement une classe concrète, sans interface ni hiérarchie de mails : un seul cas d'usage.
 */
final class Mailbox
{
    private const SUBJECT_PREFIX = 'RehearsalBox — ';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $baseUrl,
        private readonly MailRenderer $renderer = new MailRenderer(new PhpTemplateRenderer(__DIR__ . '/../../templates')),
    ) {
    }

    /**
     * Un e-mail prêt à partir : expéditeur du site, destinataire, sujet préfixé du nom du site, versions HTML et texte, logo intégré.
     * Le sujet ne doit contenir aucun texte saisi par un utilisateur qui n'ait été passé par HeaderText::oneLine.
     *
     * @param array<string, mixed> $data
     */
    public function compose(string $to, string $subject, string $template, array $data = []): Email
    {
        return $this->renderer->compose(
            (new Email())->from($this->fromAddress)->to($to)->subject(self::SUBJECT_PREFIX . $subject),
            $template,
            $data,
        );
    }

    /** Lien absolu vers une page du site, sans double barre oblique. $path commence par « / ». */
    public function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    /** Envoi qui laisse remonter l'erreur : l'appelant doit pouvoir annuler ce qu'il avait réservé (avis, jeton…). */
    public function send(RawMessage $message): void
    {
        $this->mailer->send($message);
    }

    /**
     * Envoi qui ne fait JAMAIS échouer l'appelant (cf. SafeMail) : faux si rien n'est parti.
     *
     * @param callable(): RawMessage $build
     */
    public function sendSafely(callable $build, string $failureContext): bool
    {
        return SafeMail::send($this->mailer, $build, $failureContext);
    }
}
