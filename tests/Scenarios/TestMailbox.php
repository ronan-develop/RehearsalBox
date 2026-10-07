<?php

declare(strict_types=1);

namespace App\Tests\Scenarios;

use App\Mail\Mailbox;
use Symfony\Component\Mailer\MailerInterface;

/** Boîte d'envoi des tests (#237) : expéditeur et URL de base fixes, le transport est celui que le test observe. */
final class TestMailbox
{
    public const FROM = 'no-reply@rehearsalbox.example';
    public const BASE_URL = 'https://rehearsalbox.example';

    public static function of(MailerInterface $mailer): Mailbox
    {
        return new Mailbox($mailer, self::FROM, self::BASE_URL);
    }
}
