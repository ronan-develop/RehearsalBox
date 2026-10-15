<?php

declare(strict_types=1);

namespace App\Tests\Planning\Notification;

use App\Planning\Entity\RecurringSlot;
use App\Planning\Entity\SlotException;
use App\Planning\Entity\Weekday;
use App\Planning\Repository\MysqlRecurringSlotRepository;
use App\Planning\Repository\MysqlSlotExceptionRepository;
use App\Planning\Notification\SlotExchangeNotifier;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\RecordingAfterResponse;
use App\Tests\Doubles\RecordingLogger;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Doubles\ThrowingMailer;
use App\Tests\Scenarios\MessagingScenario;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;

/** #455 : le groupe titulaire est prévenu d'une demande d'échange, le demandeur de la réponse. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class SlotExchangeNotifierTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlRecurringSlotRepository $slots;
    private MysqlSlotExceptionRepository $exceptions;
    private RecordingAfterResponse $later;
    private RecordingLogger $logger;
    private int $holderSlotId;
    private int $depecheId;
    private int $officeId;
    private int $bobId;
    private int $aliceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new RecordingLogger();
        $this->setUpScenario();
        $this->slots = new MysqlRecurringSlotRepository($this->pdo);
        $this->exceptions = new MysqlSlotExceptionRepository($this->pdo);
        $this->later = new RecordingAfterResponse();
        $alice = $this->user('Alice');
        $this->aliceId = $alice->id();
        $bob = $this->user('Bob');
        $this->bobId = $bob->id();
        $this->depecheId = $this->group('Depece', $alice)->id();
        $this->officeId = $this->group('Office', $bob)->id();
        $this->holderSlotId = $this->slots->save(new RecurringSlot(0, $this->depecheId, Weekday::Wednesday, '18:30:00', '22:45:00', true))->id();
    }

    private function notifier(MailerInterface $mailer): SlotExchangeNotifier
    {
        return new SlotExchangeNotifier(TestMailbox::of($mailer), $this->groups, $this->slots, $this->users, $this->later, $this->logger);
    }

    private function request(?string $reason = 'Concert secret', ?string $start = '19:00', ?string $end = '20:30'): SlotException
    {
        return $this->exceptions->createRequest($this->holderSlotId, new \DateTimeImmutable('2026-10-07'), $this->officeId, $this->bobId, $reason, $start, $end);
    }

    #[Test]
    public function testTheHolderGroupContactAddressIsWarnedAfterTheResponseWithTheRequesterTheDayAndTheRange(): void
    {
        $mailer = new RecordingMailer();

        $exception = $this->request();
        $this->notifier($mailer)->requested($exception);

        self::assertSame([], $mailer->sent, 'rien ne part avant la fin de la réponse');
        $this->later->runAll();
        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertSame('depece@rehearsalbox.test', $email->getTo()[0]->getAddress(), 'adresse de contact du groupe titulaire');
        $body = (string) $email->getHtmlBody() . (string) $email->getTextBody();
        self::assertStringContainsString('Office', $body);
        self::assertStringContainsString('mercredi 7 octobre 2026', $body);
        self::assertStringContainsString('19:00 – 20:30', $body);
        $link = 'https://rehearsalbox.example/exchanges/' . $exception->id();
        self::assertStringContainsString($link, (string) $email->getTextBody(), 'le lien mène à la page de la demande (#462)');
        self::assertStringContainsString('href="' . $link . '"', (string) $email->getHtmlBody());
        self::assertStringNotContainsStringIgnoringCase('onglet', $body, 'plus de consigne de chercher la demande dans le tableau de bord');
    }

    #[Test]
    public function testARequestWithoutARangeShowsTheWholeSlotAndTheReasonIsNeverRepeated(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->requested($this->request('Concert secret', null, null));
        $this->later->runAll();

        $body = (string) $mailer->sent[0]->getHtmlBody() . (string) $mailer->sent[0]->getTextBody();
        self::assertStringContainsString('18:30 – 22:45', $body, 'tout le créneau');
        self::assertStringNotContainsString('Concert secret', $body, 'texte libre d\'un autre groupe : jamais relayé par e-mail');
    }

    #[Test]
    public function testTheRequesterIsToldTheOutcomeAcceptedOrRefused(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);
        $accepted = $this->request();
        $this->exceptions->respond($accepted->id(), true, $this->aliceId, null);
        $refused = $this->exceptions->createRequest($this->holderSlotId, new \DateTimeImmutable('2026-10-14'), $this->officeId, $this->bobId, null);
        $this->exceptions->respond($refused->id(), false, $this->aliceId, null);

        $notifier->decided($this->exceptions->findById($accepted->id()) ?? throw new \LogicException());
        $notifier->decided($this->exceptions->findById($refused->id()) ?? throw new \LogicException());
        $this->later->runAll();

        self::assertSame(['bob@rehearsalbox.test', 'bob@rehearsalbox.test'], array_map(static fn ($e): string => $e->getTo()[0]->getAddress(), $mailer->sent));
        self::assertStringContainsStringIgnoringCase('accepté', (string) $mailer->sent[0]->getTextBody());
        self::assertStringContainsStringIgnoringCase('refusé', (string) $mailer->sent[1]->getTextBody());
        self::assertStringContainsString('Depece', (string) $mailer->sent[0]->getTextBody(), 'le groupe qui a répondu');
        self::assertStringNotContainsString('/exchanges/', (string) $mailer->sent[0]->getTextBody(), 'la réponse mène toujours au tableau de bord');
    }

    #[Test]
    public function testAMailFailureNeverEscapesAndTheLogHoldsOnlyIdentifiers(): void
    {
        $this->notifier(new ThrowingMailer())->requested($this->request());
        $this->later->runAll();

        $logged = json_encode($this->logger->records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Notification d\'échange', $logged);
        self::assertStringNotContainsString('@', $logged, 'ni adresse ni contenu dans le journal');
    }

    #[Test]
    public function testAnInvalidContactAddressIsSkippedAndLogged(): void
    {
        $this->pdo->exec("UPDATE `groups` SET contact_email = 'pas-une-adresse' WHERE id = " . $this->depecheId);
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->requested($this->request());
        $this->later->runAll();

        self::assertSame([], $mailer->sent);
        self::assertNotSame([], $this->logger->records);
    }
}
