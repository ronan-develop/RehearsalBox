<?php

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\SafeMail;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Doubles\ThrowingMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

final class SafeMailTest extends TestCase
{
    private function email(): Email
    {
        return (new Email())->from('no-reply@rehearsalbox.example')->to('alice@rehearsalbox.test')->subject('Sujet')->text('Corps');
    }

    #[Test]
    public function testAMailThatLeavesIsReportedAsSent(): void
    {
        $mailer = new RecordingMailer();

        $sent = SafeMail::send($mailer, fn (): Email => $this->email(), 'Contexte');

        self::assertTrue($sent);
        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testEveryKindOfFailureIsAbsorbedAndReportedAsNotSent(): void
    {
        $log = $this->captureErrorLog(function (): void {
            self::assertFalse(SafeMail::send(new FailingMailer(), fn (): Email => $this->email(), 'Transport'));
            self::assertFalse(SafeMail::send(new ThrowingMailer(), fn (): Email => $this->email(), 'Bogue'));
            self::assertFalse(SafeMail::send(new RecordingMailer(), static function (): never {
                throw new \RuntimeException('gabarit cassé');
            }, 'Gabarit'));
        });

        self::assertStringContainsString('Transport', $log);
        self::assertStringContainsString('TransportException', $log);
        self::assertStringContainsString('LogicException', $log);
        self::assertStringContainsString('RuntimeException', $log);
    }

    #[Test]
    public function testTheLogNeverCarriesTheMessageOfTheErrorNorAnAddress(): void
    {
        $log = $this->captureErrorLog(function (): void {
            SafeMail::send(new RecordingMailer(), static function (): never {
                throw new \RuntimeException('alice@rehearsalbox.test jeton-secret');
            }, 'Contexte (utilisateur #3)');
        });

        self::assertStringContainsString('Contexte (utilisateur #3)', $log);
        self::assertStringNotContainsString('alice', $log);
        self::assertStringNotContainsString('jeton-secret', $log);
    }

    /** @param callable(): void $action */
    private function captureErrorLog(callable $action): string
    {
        $file = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $file);
        try {
            $action();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $content = (string) file_get_contents($file);
        unlink($file);

        return $content;
    }
}
