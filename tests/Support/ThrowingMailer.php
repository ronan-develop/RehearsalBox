<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/** Faux mailer de test : échoue avec une exception qui n'est PAS une erreur de transport (gabarit cassé, bogue…). */
final class ThrowingMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new \LogicException('erreur inattendue');
    }
}
