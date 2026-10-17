<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Notification;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Accounting\Notification\TreasurerReminderService;
use App\Accounting\Repository\Month\MysqlMonthMarkRepository;
use App\Accounting\Repository\MysqlStatementRepository;
use App\Accounting\Repository\Notice\MysqlReminderNoticeRepository;
use App\Accounting\Service\StatementGapCalculator;
use App\Accounting\Todo\TodoPlanner;
use App\Accounting\Todo\TodoService;
use App\Assembly\Entity\BoardPost;
use App\Association\Repository\MysqlAssociationBoardRepository;
use App\Security\AdminAccess;
use App\Tests\Accounting\Service\AccountingTestCase;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;

/** #362 livraison 8 : rappel e-mail des tâches en retard : destinataires, une fois par semaine, copie au président, discrétion, panne d'envoi. */
final class TreasurerReminderServiceTest extends AccountingTestCase
{
    private User $treasurer;
    private User $president;

    protected function setUp(): void
    {
        parent::setUp();
        $users = new MysqlUserRepository($this->pdo);
        $this->treasurer = $users->save(new User(0, 'tresorier@rehearsalbox.test', 'hash', 'Gueguin Denis', UserRole::Musicien, true, 0, null));
        $this->president = $users->save(new User(0, 'president@rehearsalbox.test', 'hash', 'Younasse', UserRole::Musicien, true, 0, null));
        $board = new MysqlAssociationBoardRepository($this->pdo);
        $board->assign(BoardPost::Tresorier, $this->treasurer->id(), 'Denis Gueguin', $this->admin->id(), $this->clock);
        $board->assign(BoardPost::President, $this->president->id(), 'Younasse X', $this->admin->id(), $this->clock);
        $this->openYear();
    }

    private function service(MailerInterface $mailer, string $now, bool $enabled = true): TreasurerReminderService
    {
        $todo = new TodoService(new AdminAccess(), new TodoPlanner(), $this->yearRepository, $this->entryRepository, new MysqlMonthMarkRepository($this->pdo), new MysqlStatementRepository($this->pdo), new StatementGapCalculator($this->entryRepository), $this->occurrences, new NullLogger());

        return new TreasurerReminderService($todo, new MysqlAssociationBoardRepository($this->pdo), new MysqlUserRepository($this->pdo), new MysqlReminderNoticeRepository($this->pdo), TestMailbox::of($mailer), new MockClock($now), new \DateTimeZone('Europe/Paris'), $enabled);
    }

    /** @return list<string> adresses des destinataires */
    private function recipients(RecordingMailer $mailer): array
    {
        return array_map(static fn ($m): string => $m->getTo()[0]->getAddress(), $mailer->sent);
    }

    #[Test]
    public function testNothingIsSentWhenDisabledOutsideDaytimeOrWhenNothingIsLate(): void
    {
        $mailer = new RecordingMailer();

        self::assertSame(0, $this->service($mailer, '2025-12-10 10:00:00', false)->sendDue()->sent());
        self::assertTrue($this->service($mailer, '2025-12-10 03:00:00')->sendDue()->outsideWindow());
        self::assertSame(0, $this->service($mailer, '2025-10-20 10:00:00')->sendDue()->sent(), 'octobre n\'est pas fini');
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testTheTreasurerGetsOneDiscreetSummaryAndNoOneElseWhenTheDelayIsShort(): void
    {
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2025-11-12 10:00:00')->sendDue();

        self::assertSame([1, 0], [$report->sent(), $report->failed()]);
        self::assertSame(['tresorier@rehearsalbox.test'], $this->recipients($mailer));
        $email = $mailer->sent[0];
        $text = (string) $email->getSubject() . (string) $email->getTextBody() . (string) $email->getHtmlBody();
        self::assertStringContainsString('/admin/accounting', $text);
        self::assertStringContainsString('2 tâche', (string) $email->getTextBody());
        foreach (['Gueguin', 'Denis', 'Younasse', '€', 'IBAN'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text);
        }
        self::assertDoesNotMatchRegularExpression('/\d+[,.]\d{2}/', (string) $email->getTextBody());
    }

    #[Test]
    public function testASecondRunWithinAWeekSendsNothingAndAWeekLaterSendsAgain(): void
    {
        $mailer = new RecordingMailer();
        $this->service($mailer, '2025-11-12 10:00:00')->sendDue();

        $again = $this->service($mailer, '2025-11-13 10:00:00')->sendDue();
        $later = $this->service($mailer, '2025-11-19 11:00:00')->sendDue();

        self::assertSame(0, $again->sent());
        self::assertSame(1, $again->skipped());
        self::assertSame(1, $later->sent());
        self::assertCount(2, $mailer->sent);
    }

    #[Test]
    public function testThePresidentIsCopiedOnceTheDelayExceedsFourteenDays(): void
    {
        $mailer = new RecordingMailer();

        $report = $this->service($mailer, '2025-12-10 10:00:00')->sendDue();

        self::assertSame(2, $report->sent());
        self::assertEqualsCanonicalizing(['tresorier@rehearsalbox.test', 'president@rehearsalbox.test'], $this->recipients($mailer));
        $president = array_values(array_filter($mailer->sent, static fn ($m): bool => $m->getTo()[0]->getAddress() === 'president@rehearsalbox.test'))[0];
        self::assertStringContainsString('14 jours', (string) $president->getTextBody());
        self::assertStringNotContainsString('Gueguin', (string) $president->getTextBody() . (string) $president->getHtmlBody());
    }

    #[Test]
    public function testAPresidentWhoIsAlsoTheTreasurerReceivesOnlyOneMessage(): void
    {
        (new MysqlAssociationBoardRepository($this->pdo))->assign(BoardPost::President, $this->treasurer->id(), 'Denis Gueguin', $this->admin->id(), $this->clock);
        $mailer = new RecordingMailer();

        $this->service($mailer, '2025-12-10 10:00:00')->sendDue();

        self::assertSame(['tresorier@rehearsalbox.test'], $this->recipients($mailer));
    }

    #[Test]
    public function testAVacantPostOrADisabledAccountReceivesNothingAndTheProblemIsNamed(): void
    {
        $board = new MysqlAssociationBoardRepository($this->pdo);
        $mailer = new RecordingMailer();
        $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->treasurer->id()]);

        $report = $this->service($mailer, '2025-11-12 10:00:00')->sendDue();

        self::assertSame(0, $report->sent());
        self::assertStringContainsString('désactivé', (string) $this->service($mailer, '2025-11-12 10:00:00')->recipientProblem());
        $board->vacate(BoardPost::Tresorier);
        self::assertStringContainsString('vide', (string) $this->service($mailer, '2025-11-12 10:00:00')->recipientProblem());
        self::assertSame(0, $this->service($mailer, '2025-11-12 10:00:00')->sendDue()->sent());
        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAnActiveTreasurerHasNoProblem(): void
    {
        self::assertNull($this->service(new RecordingMailer(), '2025-11-12 10:00:00')->recipientProblem());
    }

    #[Test]
    public function testAFailedSendIsReportedAndTheNextRunRetries(): void
    {
        $report = $this->service(new FailingMailer(), '2025-11-12 10:00:00')->sendDue();
        self::assertSame([0, 1], [$report->sent(), $report->failed()]);

        $mailer = new RecordingMailer();
        $retry = $this->service($mailer, '2025-11-12 11:00:00')->sendDue();

        self::assertSame(1, $retry->sent(), 'la réservation a été libérée');
    }

    #[Test]
    public function testRemindersStopOnceEverythingIsDone(): void
    {
        $mailer = new RecordingMailer();
        $year = (int) $this->pdo->query('SELECT id FROM accounting_fiscal_years')->fetchColumn();
        $marks = new MysqlMonthMarkRepository($this->pdo);
        $marks->mark($year, new \DateTimeImmutable('2025-10-01'), $this->admin->id(), $this->clock);
        $this->record($year, ['occurredOn' => '2025-10-10']);
        $upload = sys_get_temp_dir() . '/rb-rem-' . bin2hex(random_bytes(4));
        file_put_contents($upload, "%PDF-1.4\n1 0 obj\n<< >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $this->statements->deposit($this->admin, $year, ['periodStart' => '2025-10-01', 'periodEnd' => '2025-10-31', 'closingBalance' => '120,00'], $upload, 'r.pdf', (int) filesize($upload), $this->clock);
        unlink($upload);

        $report = $this->service($mailer, '2025-11-12 10:00:00')->sendDue();

        self::assertSame(0, $report->sent());
        self::assertSame([], $mailer->sent);
    }
}
