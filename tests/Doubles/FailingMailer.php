<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/** Faux mailer de test : tout envoi échoue comme un serveur SMTP indisponible. */
final class FailingMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new TransportException('SMTP indisponible');
    }
}
