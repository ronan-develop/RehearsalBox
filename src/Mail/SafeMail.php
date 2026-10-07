<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Envoi d'un e-mail qui ne doit JAMAIS faire échouer l'appelant (jeton de réinitialisation, alertes, notifications) : toute
 * erreur (serveur SMTP, gabarit, bogue) est absorbée et seule sa CLASSE est journalisée avec le contexte de l'appelant, jamais
 * le message de l'erreur (il pourrait contenir une adresse ou un jeton). Renvoie false si rien n'est parti, pour que
 * l'appelant annule ce qui doit l'être (ex. un jeton qui n'a pas pu être remis).
 */
final class SafeMail
{
    /**
     * @param callable(): RawMessage $build construit le message (le gabarit peut échouer, d'où l'appel à l'intérieur du try)
     * @param string                 $failureContext ce qui est journalisé en cas d'échec, sans donnée personnelle (ex. « … (utilisateur #3) »)
     */
    public static function send(MailerInterface $mailer, callable $build, string $failureContext, LoggerInterface $logger = new NullLogger()): bool
    {
        try {
            $mailer->send($build());

            return true;
        } catch (\Throwable $e) {
            $logger->error($failureContext, ['exception' => $e::class]);

            return false;
        }
    }
}
