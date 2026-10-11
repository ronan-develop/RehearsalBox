<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Announcement;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\UserRepositoryInterface;
use App\Account\Service\Announcement\AnnouncementLedger;
use App\Account\Service\Announcement\AnnouncementService;
use App\Mail\Mailbox;
use App\Tests\Doubles\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/** #444 : e-mail d'annonce envoyé UNE fois à chaque compte actif — simulation par défaut, reprise sans doublon. */
final class AnnouncementServiceTest extends TestCase
{
    private RecordingMailer $mailer;
    private string $ledgerPath;

    protected function setUp(): void
    {
        $this->mailer = new RecordingMailer();
        $this->ledgerPath = sys_get_temp_dir() . '/rb-announcement-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
    }

    private function user(int $id, string $email, bool $active = true): User
    {
        return new User($id, $email, 'hash', 'Nom ' . $id, UserRole::Musicien, $active, 0, null);
    }

    /** @param list<User> $users */
    private function service(array $users, ?MailerInterface $mailer = null): AnnouncementService
    {
        $repository = $this->createStub(UserRepositoryInterface::class);
        $repository->method('findAll')->willReturn($users);

        return new AnnouncementService(
            $repository,
            new Mailbox($mailer ?? $this->mailer, 'no-reply@rehearsalbox.example', 'https://b27.example'),
            new AnnouncementLedger($this->ledgerPath),
        );
    }

    #[Test]
    public function testDryRunCountsTheActiveRecipientsAndSendsNothing(): void
    {
        $report = $this->service([$this->user(1, 'a@x.test'), $this->user(2, 'b@x.test'), $this->user(3, 'off@x.test', false)])->dryRun();

        self::assertSame(2, $report->pending());
        self::assertSame(0, $report->sent());
        self::assertSame([], $this->mailer->sent);
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    #[Test]
    public function testSendMailsEveryActiveAccountWithTheTwoLinksAndSkipsInactiveOnes(): void
    {
        $report = $this->service([$this->user(1, 'a@x.test'), $this->user(2, 'b@x.test'), $this->user(3, 'off@x.test', false)])->sendToAll();

        self::assertSame(2, $report->sent());
        self::assertSame(0, $report->failed());
        self::assertCount(2, $this->mailer->sent);
        self::assertSame('a@x.test', $this->mailer->sent[0]->getTo()[0]->getAddress());
        $html = (string) $this->mailer->sent[0]->getHtmlBody();
        self::assertStringContainsString('https://b27.example/forgot-password', $html);
        self::assertStringContainsString('href="https://b27.example/login"', $html);
    }

    #[Test]
    public function testASecondRunSendsNothingMoreBecauseEachSentAccountIsRemembered(): void
    {
        $users = [$this->user(1, 'a@x.test'), $this->user(2, 'b@x.test')];
        $this->service($users)->sendToAll();
        $second = $this->service($users)->sendToAll();

        self::assertSame(0, $second->sent());
        self::assertSame(2, $second->alreadySent());
        self::assertCount(2, $this->mailer->sent, 'aucun doublon');
    }

    #[Test]
    public function testAFailedSendIsCountedNotRememberedAndRetriedOnTheNextRun(): void
    {
        $flaky = new class () implements MailerInterface {
            public int $calls = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('SMTP indisponible');
                }
            }
        };
        $users = [$this->user(1, 'a@x.test'), $this->user(2, 'b@x.test')];

        $first = $this->service($users, $flaky)->sendToAll();
        self::assertSame(1, $first->failed());
        self::assertSame(1, $first->sent(), "l'échec d'un compte n'arrête pas les suivants");

        $second = $this->service($users, $flaky)->sendToAll();
        self::assertSame(1, $second->sent(), 'seul le compte en échec est réessayé');
        self::assertSame(1, $second->alreadySent());
    }

    #[Test]
    public function testTestSendGoesToOneAddressOnlyAndDoesNotTouchTheLedger(): void
    {
        $report = $this->service([$this->user(1, 'a@x.test')])->sendTest('moi@x.test');

        self::assertSame(1, $report->sent());
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('moi@x.test', $this->mailer->sent[0]->getTo()[0]->getAddress());
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    #[Test]
    public function testTheLedgerIgnoresGarbageLines(): void
    {
        file_put_contents($this->ledgerPath, "1\nabc\n\n2\n");
        $ledger = new AnnouncementLedger($this->ledgerPath);

        self::assertTrue($ledger->has(1));
        self::assertTrue($ledger->has(2));
        self::assertFalse($ledger->has(3));
    }
}
