<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\Mailbox;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #237 : tout ce qui voyage ensemble pour envoyer un e-mail du site (expéditeur, URL de base, gabarits, envoi). */
final class MailboxTest extends TestCase
{
    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        $this->mailer = new RecordingMailer();
    }

    private function mailbox(string $baseUrl = 'https://rehearsalbox.example'): Mailbox
    {
        return new Mailbox($this->mailer, 'no-reply@rehearsalbox.example', $baseUrl);
    }

    #[Test]
    public function testComposeSetsTheSenderTheRecipientAndTheSiteNamePrefixedSubject(): void
    {
        $email = $this->mailbox()->compose('alice@rehearsalbox.test', 'votre mot de passe a été modifié', 'password-reset', ['link' => 'https://x.test/y']);

        self::assertSame('no-reply@rehearsalbox.example', $email->getFrom()[0]->getAddress());
        self::assertSame('alice@rehearsalbox.test', $email->getTo()[0]->getAddress());
        self::assertSame('RehearsalBox — votre mot de passe a été modifié', $email->getSubject());
    }

    #[Test]
    public function testComposeBuildsTheHtmlAndTextVersionsWithTheEmbeddedLogo(): void
    {
        $email = $this->mailbox()->compose('alice@rehearsalbox.test', 'Sujet', 'password-reset', ['link' => 'https://x.test/reset', 'preheader' => 'Lien valable 1 heure']);

        self::assertStringContainsString('https://x.test/reset', (string) $email->getHtmlBody());
        self::assertStringContainsString('https://x.test/reset', (string) $email->getTextBody());
        self::assertCount(1, $email->getAttachments(), 'le logo du site en pièce jointe en ligne');
    }

    #[Test]
    public function testUrlJoinsTheBaseAndThePathWithASingleSlash(): void
    {
        self::assertSame('https://rehearsalbox.example/messages/3', $this->mailbox('https://rehearsalbox.example')->url('/messages/3'));
        self::assertSame('https://rehearsalbox.example/messages/3', $this->mailbox('https://rehearsalbox.example/')->url('/messages/3'));
        self::assertSame('https://rehearsalbox.example/reset?token=abc', $this->mailbox('https://rehearsalbox.example//')->url('/reset?token=abc'));
    }

    #[Test]
    public function testSendHandsTheMessageToTheMailer(): void
    {
        $box = $this->mailbox();

        $box->send($box->compose('alice@rehearsalbox.test', 'Sujet', 'password-reset', ['link' => 'https://x.test']));

        self::assertCount(1, $this->mailer->sent);
    }

    #[Test]
    public function testSendSafelyReportsSuccessAndNeverThrowsOnFailure(): void
    {
        $box = $this->mailbox();
        $build = static fn () => (new \Symfony\Component\Mime\Email())->from('a@b.test')->to('c@d.test')->subject('S')->text('T');

        self::assertTrue($box->sendSafely($build, 'Contexte'));
        self::assertCount(1, $this->mailer->sent);

        $logger = new \App\Tests\Doubles\RecordingLogger();
        $failing = new Mailbox(new FailingMailer(), 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example', logger: $logger);
        self::assertFalse($failing->sendSafely($build, 'Contexte test'));
        self::assertStringContainsString('Contexte test', $logger->text());
    }
}
