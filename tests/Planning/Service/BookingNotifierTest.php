<?php

declare(strict_types=1);

namespace App\Tests\Planning\Service;

use App\Planning\Entity\FreeSlotBookingStatus;
use App\Account\Entity\UserRole;
use App\Planning\Entity\Requester;
use App\Planning\Entity\TimeRange;
use App\Account\Entity\User;
use App\Planning\Repository\MysqlFreeSlotBookingRepository;
use App\Account\Repository\MysqlNotificationPreferenceRepository;
use App\Planning\Service\BookingNotifier;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use App\Tests\Doubles\RecordingAfterResponse;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Doubles\ThrowingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;
use App\Tests\Scenarios\TestMailbox;

/** #263 partie 3a : l'e-mail « à valider » des administrateurs et l'e-mail d'issue du demandeur. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class BookingNotifierTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlFreeSlotBookingRepository $bookings;
    private RecordingAfterResponse $later;
    private int $aliceId;
    private int $groupId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->bookings = new MysqlFreeSlotBookingRepository($this->pdo);
        $this->later = new RecordingAfterResponse();
        $alice = $this->user('Alice');
        $this->aliceId = $alice->id();
        $this->groupId = $this->group('Alpha', $alice)->id();
    }

    private function admin(string $name, bool $active = true): User
    {
        return $this->users->save(new User(0, strtolower($name) . '.admin@rehearsalbox.test', 'hash', $name, UserRole::Admin, $active, 0, null));
    }

    private function notifier(MailerInterface $mailer): BookingNotifier
    {
        return new BookingNotifier(TestMailbox::of($mailer), $this->users, $this->groups, $this->later);
    }

    private function booking(?string $reason = 'Enregistrement secret'): \App\Planning\Entity\FreeSlotBooking
    {
        return $this->bookings->create(new Requester($this->groupId, $this->aliceId), new \DateTimeImmutable('2026-10-07'), new TimeRange('09:00:00', '14:00:00'), $reason);
    }

    /** @return list<string> adresses des destinataires du dernier lot */
    private function recipients(RecordingMailer $mailer): array
    {
        return array_map(static fn ($email): string => $email->getTo()[0]->getAddress(), $mailer->sent);
    }

    #[Test]
    public function testEveryActiveAdminIsAlertedAndNobodyElseNorBeforeTheResponseIsSent(): void
    {
        $this->admin('Zoe');
        $this->admin('Yann');
        $this->admin('Inactif', false);
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->bookingRequested($this->booking());

        self::assertSame([], $mailer->sent, 'rien ne part avant la fin de la réponse');
        $this->later->runAll();
        self::assertEqualsCanonicalizing(['yann.admin@rehearsalbox.test', 'zoe.admin@rehearsalbox.test'], $this->recipients($mailer), 'ni l\'admin inactif ni le musicien');
    }

    #[Test]
    public function testTheAlertNamesTheGroupTheDayAndTheRangeWithALinkButNeverTheReason(): void
    {
        $this->admin('Zoe');
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->bookingRequested($this->booking('Enregistrement secret'));
        $this->later->runAll();

        $email = $mailer->sent[0];
        self::assertSame('no-reply@rehearsalbox.example', $email->getFrom()[0]->getAddress());
        self::assertStringContainsString('Alpha', (string) $email->getHtmlBody());
        self::assertStringContainsString('mercredi 7 octobre 2026', (string) $email->getHtmlBody());
        self::assertStringContainsString('09:00 – 14:00', (string) $email->getTextBody());
        self::assertStringContainsString('https://rehearsalbox.example/admin/bookings', (string) $email->getTextBody());
        self::assertStringNotContainsString('Enregistrement secret', (string) $email->getHtmlBody() . (string) $email->getTextBody());
    }

    #[Test]
    public function testTheAlertCannotBeSwitchedOffByTheGeneralPreference(): void
    {
        $zoe = $this->admin('Zoe');
        (new MysqlNotificationPreferenceRepository($this->pdo))->setEmailEnabled($zoe->id(), false);
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->bookingRequested($this->booking());
        $this->later->runAll();

        self::assertSame(['zoe.admin@rehearsalbox.test'], $this->recipients($mailer), 'alerte de gestion : non désactivable');
    }

    #[Test]
    public function testTheRequesterLearnsTheOutcomeValidatedOrRefusedWithTheNoteOnlyOnRefusal(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);
        $admin = $this->admin('Zoe');

        $validated = $this->booking();
        $this->bookings->decide($validated->id(), FreeSlotBookingStatus::Validee, $admin->id(), null, $this->now);
        $notifier->bookingDecided($this->bookings->findById($validated->id()) ?? throw new \LogicException());

        $refused = $this->bookings->create(new Requester($this->groupId, $this->aliceId), new \DateTimeImmutable('2026-10-08'), new TimeRange('10:00:00', '11:00:00'), null);
        $this->bookings->decide($refused->id(), FreeSlotBookingStatus::Refusee, $admin->id(), 'Local fermé', $this->now);
        $notifier->bookingDecided($this->bookings->findById($refused->id()) ?? throw new \LogicException());

        $this->later->runAll();
        self::assertSame(['alice@rehearsalbox.test', 'alice@rehearsalbox.test'], $this->recipients($mailer));
        self::assertStringContainsStringIgnoringCase('validée', (string) $mailer->sent[0]->getTextBody());
        self::assertStringNotContainsString('Local fermé', (string) $mailer->sent[0]->getTextBody());
        self::assertStringContainsStringIgnoringCase('refusée', (string) $mailer->sent[1]->getTextBody());
        self::assertStringContainsString('Local fermé', (string) $mailer->sent[1]->getTextBody());
    }

    #[Test]
    public function testAnInvalidAddressOrAnInactiveRequesterReceivesNothingAndNothingEverFails(): void
    {
        $mailer = new RecordingMailer();
        $this->users->save(new User($this->aliceId, 'pas-une-adresse', 'hash', 'Alice', UserRole::Musicien, true, 0, null));

        $logged = $this->captureLog(function () use ($mailer): void {
            $this->notifier($mailer)->bookingDecided($this->booking());
            $this->later->runAll();
        });
        self::assertStringContainsString('adresse invalide', $logged);
        self::assertStringNotContainsString('pas-une-adresse', $logged, 'jamais l\'adresse dans le journal');

        $this->users->save(new User($this->aliceId, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, false, 0, null));
        $this->notifier($mailer)->bookingDecided($this->booking());
        $this->later->runAll();

        self::assertSame([], $mailer->sent);
    }

    private function captureLog(callable $action): string
    {
        $log = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $log);
        try {
            $action();
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        return $logged;
    }

    #[Test]
    public function testAMailerFailureIsLoggedWithoutAnyAddressAndNeverReachesTheCaller(): void
    {
        $this->admin('Zoe');
        $logged = $this->captureLog(function (): void {
            $this->notifier(new ThrowingMailer())->bookingRequested($this->booking());
            $this->later->runAll();
        });

        self::assertStringContainsString('Notification de réservation', $logged);
        self::assertStringNotContainsString('@', $logged, 'jamais d\'adresse dans le journal');
    }
}
