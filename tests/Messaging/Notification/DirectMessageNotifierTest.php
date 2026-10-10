<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Notification;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Messaging\Entity\Conversation;
use App\Messaging\Notification\DirectMessageNotifier;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\Notice\MysqlMentionNoticeRepository;
use App\Messaging\Repository\Participation\MysqlConversationMuteRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\TestMailbox;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;

/** #269 : l'e-mail « X vous a écrit » d'un message direct : sans texte ni adresse, sourdine par conversation seulement. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DirectMessageNotifierTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlMentionNoticeRepository $notices;
    private MysqlConversationMuteRepository $mutes;
    private MysqlUserRepository $users;
    private Conversation $conversation;
    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices = new MysqlMentionNoticeRepository($this->pdo);
        $this->mutes = new MysqlConversationMuteRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        foreach (['alice', 'bob'] as $name) {
            $this->people[$name] = $this->users->save(new User(0, "{$name}.perso@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $this->conversation = (new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->openDirect($this->id('alice'), $this->id('bob'), $this->now->modify('-2 days'));
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function notifier(MailerInterface $mailer): DirectMessageNotifier
    {
        return new DirectMessageNotifier(TestMailbox::of($mailer), $this->notices, $this->users, $this->mutes);
    }

    private function write(DirectMessageNotifier $notifier, ?\DateTimeImmutable $at = null): void
    {
        $notifier->newMessage($this->conversation, $this->id('alice'), 'Alice', $at ?? $this->now);
    }

    #[Test]
    public function testTheOtherPersonReceivesOneEmailWithALinkAndNeitherTextNorSenderAddress(): void
    {
        $mailer = new RecordingMailer();

        $this->write($this->notifier($mailer));

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertSame(['bob.perso@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertStringContainsString('Alice', (string) $email->getSubject());
        foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
            self::assertStringContainsString('https://rehearsalbox.example/messages/' . $this->conversation->id(), $body);
            self::assertStringNotContainsString('alice.perso@rehearsalbox.test', $body, "jamais l'adresse de l'expéditeur");
            self::assertStringNotContainsString('/account/password', $body, 'pas de désabonnement général : seulement la sourdine');
        }
    }

    #[Test]
    public function testASecondMessageWithinTwentyFourHoursSendsNothingThenAfterwardsItDoes(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);

        $this->write($notifier);
        $this->write($notifier, $this->now->modify('+3 hours'));
        self::assertCount(1, $mailer->sent);

        $this->write($notifier, $this->now->modify('+25 hours'));
        self::assertCount(2, $mailer->sent);
    }

    #[Test]
    public function testAMutedConversationSendsNothingAndReservesNothingThenResumesWhenUnmuted(): void
    {
        $this->mutes->setMuted($this->conversation->id(), $this->id('bob'), true);
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);

        $this->write($notifier);
        self::assertSame([], $mailer->sent);
        self::assertNull($this->notices->find($this->conversation->id(), $this->id('bob')));

        $this->mutes->setMuted($this->conversation->id(), $this->id('bob'), false);
        $this->write($notifier, $this->now->modify('+1 hour'));
        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testAnInactiveRecipientReceivesNothing(): void
    {
        $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$this->id('bob')]);
        $mailer = new RecordingMailer();

        $this->write($this->notifier($mailer));

        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAGroupConversationIsNotOurBusiness(): void
    {
        $mailer = new RecordingMailer();
        $groupConversation = new Conversation(99, 1, 2, null, $this->now);

        $this->notifier($mailer)->newMessage($groupConversation, $this->id('alice'), 'Alice', $this->now);

        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAMailTransportFailureNeverReachesTheAuthorAndKeepsARetryPossible(): void
    {
        $this->write($this->notifier(new FailingMailer()));

        self::assertNull($this->notices->find($this->conversation->id(), $this->id('bob')), 'réservation annulée');
        $mailer = new RecordingMailer();
        $this->write($this->notifier($mailer), $this->now->modify('+1 minute'));
        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testTheHourlyCapPerAuthorApplies(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);
        $carols = [];
        for ($i = 0; $i < DirectMessageNotifier::MAX_PER_AUTHOR_PER_HOUR + 1; $i++) {
            $carols[] = $this->users->save(new User(0, "p{$i}@rehearsalbox.test", 'hash', "P{$i}", UserRole::Musicien, true, 0, null));
        }
        $repository = new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        foreach ($carols as $person) {
            $notifier->newMessage($repository->openDirect($this->id('alice'), $person->id(), $this->now), $this->id('alice'), 'Alice', $this->now);
        }

        self::assertCount(DirectMessageNotifier::MAX_PER_AUTHOR_PER_HOUR, $mailer->sent);
    }
}
