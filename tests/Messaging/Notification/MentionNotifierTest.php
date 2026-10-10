<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Notification;

use App\Messaging\Entity\Conversation;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\Participation\MysqlConversationGuestRepository;
use App\Messaging\Repository\Participation\MysqlConversationMuteRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Messaging\Repository\Notice\MysqlMentionNoticeRepository;
use App\Account\Repository\MysqlNotificationPreferenceRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Messaging\Notification\MentionNotifier;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;
use App\Tests\Scenarios\TestMailbox;

/** #178 : l'e-mail « vous avez été mentionné » — un par conversation et par personne toutes les 24 h, désinscription respectée. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MentionNotifierTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlMentionNoticeRepository $notices;
    private MysqlNotificationPreferenceRepository $preferences;
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
        $this->preferences = new MysqlNotificationPreferenceRepository($this->pdo);
        $this->mutes = new MysqlConversationMuteRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'bob', 'denis'] as $name) {
            $this->people[$name] = $this->users->save(new User(0, "{$name}.perso@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'contact-alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'contact-beta@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->id('alice'));
        $groups->addMember($beta, $this->id('bob'));
        $conversations = new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $this->conversation = $conversations->create($alpha, $beta, 'Titre secret', $this->now->modify('-2 days'), $this->id('alice'));
        (new MysqlConversationGuestRepository($this->pdo))->add($this->conversation->id(), $this->id('denis'), $this->id('alice'), $this->now->modify('-1 day'));
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function notifier(MailerInterface $mailer): MentionNotifier
    {
        return new MentionNotifier(TestMailbox::of($mailer), $this->notices, $this->users, $this->preferences, $this->mutes);
    }

    private function mention(MentionNotifier $notifier, array $ids, ?\DateTimeImmutable $at = null): void
    {
        $notifier->mentioned($this->conversation, $this->id('alice'), 'Alice', $ids, $at ?? $this->now);
    }

    #[Test]
    public function testTheTaggedPersonReceivesOneEmailAtTheirAccountAddressWithALinkAndNoContent(): void
    {
        $mailer = new RecordingMailer();

        $this->mention($this->notifier($mailer), [$this->id('denis')]);

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertSame(['denis.perso@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertSame('no-reply@rehearsalbox.example', $email->getFrom()[0]->getAddress());
        self::assertStringContainsString('Alice', (string) $email->getSubject());
        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        foreach ([$html, $text] as $body) {
            self::assertStringContainsString('https://rehearsalbox.example/messages/' . $this->conversation->id(), $body);
            self::assertStringContainsString('/account/password', $body, 'lien vers la désinscription (Mon compte)');
            self::assertStringNotContainsString('Titre secret', $body, 'ni titre ni texte du message');
            self::assertStringContainsString('Alice', $body);
        }
        self::assertStringNotContainsString('Titre secret', (string) $email->getSubject());
    }

    #[Test]
    public function testTheAuthorIsNeverNotifiedAboutTheirOwnMention(): void
    {
        $mailer = new RecordingMailer();

        $this->mention($this->notifier($mailer), [$this->id('alice'), $this->id('bob')]);

        self::assertCount(1, $mailer->sent);
        self::assertSame('bob.perso@rehearsalbox.test', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    #[Test]
    public function testASecondMentionWithinTwentyFourHoursSendsNothingThenAfterwardsItDoes(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);

        $this->mention($notifier, [$this->id('denis')]);
        $this->mention($notifier, [$this->id('denis')], $this->now->modify('+3 hours'));
        self::assertCount(1, $mailer->sent, 'dix tags en une heure : un seul e-mail');

        $this->mention($notifier, [$this->id('denis')], $this->now->modify('+25 hours'));
        self::assertCount(2, $mailer->sent, 'après 24 h, un nouvel e-mail');
    }

    #[Test]
    public function testAnUnsubscribedPersonReceivesNothing(): void
    {
        $this->preferences->setEmailEnabled($this->id('denis'), false);
        $mailer = new RecordingMailer();

        $this->mention($this->notifier($mailer), [$this->id('denis')]);

        self::assertSame([], $mailer->sent);
        self::assertNull($this->notices->find($this->conversation->id(), $this->id('denis')), 'rien n\'est réservé');
    }

    #[Test]
    public function testAMutedConversationSendsNothingAndReservesNothingThenResumesWhenUnmuted(): void
    {
        $this->mutes->setMuted($this->conversation->id(), $this->id('denis'), true);
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);

        $this->mention($notifier, [$this->id('denis')]);

        self::assertSame([], $mailer->sent, 'sourdine : pas d\'e-mail de mention, quelle que soit la mention');
        self::assertNull($this->notices->find($this->conversation->id(), $this->id('denis')), 'rien n\'est réservé');

        $this->mutes->setMuted($this->conversation->id(), $this->id('denis'), false);
        $this->mention($notifier, [$this->id('denis')]);
        self::assertCount(1, $mailer->sent, 'sourdine levée : l\'e-mail repart');
    }

    #[Test]
    public function testMutingOneConversationDoesNotSilenceAnotherOne(): void
    {
        $other = (new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->create(
            $this->conversation->initiatorGroupId(),
            $this->conversation->targetGroupId(),
            'Autre fil',
            $this->now->modify('-1 day'),
            $this->id('alice'),
        );
        (new MysqlConversationGuestRepository($this->pdo))->add($other->id(), $this->id('denis'), $this->id('alice'), $this->now->modify('-1 day'));
        $this->mutes->setMuted($this->conversation->id(), $this->id('denis'), true);
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->mentioned($other, $this->id('alice'), 'Alice', [$this->id('denis')], $this->now);

        self::assertCount(1, $mailer->sent, 'la sourdine est propre à une conversation');
    }

    #[Test]
    public function testInactiveUnknownOrInvalidAddressesAreSkippedWithoutBlockingTheOthers(): void
    {
        $inactive = $this->users->save(new User(0, 'parti@rehearsalbox.test', 'hash', 'Parti', UserRole::Musicien, false, 0, null));
        $broken = $this->users->save(new User(0, 'pas-une-adresse', 'hash', 'Cassé', UserRole::Musicien, true, 0, null));
        $mailer = new RecordingMailer();

        $this->mention($this->notifier($mailer), [$inactive->id(), 999999, $broken->id(), $this->id('denis')]);

        self::assertCount(1, $mailer->sent);
        self::assertSame('denis.perso@rehearsalbox.test', $mailer->sent[0]->getTo()[0]->getAddress());
    }

    #[Test]
    public function testAnAuthorCannotFloodInboxesBeyondTheHourlyCap(): void
    {
        $users = [];
        foreach (range(1, MentionNotifier::MAX_PER_AUTHOR_PER_HOUR + 3) as $i) {
            $users[] = $this->users->save(new User(0, "membre{$i}@rehearsalbox.test", 'hash', "Membre {$i}", UserRole::Musicien, true, 0, null))->id();
        }
        $mailer = new RecordingMailer();

        $this->mention($this->notifier($mailer), $users);

        self::assertCount(MentionNotifier::MAX_PER_AUTHOR_PER_HOUR, $mailer->sent);
    }

    #[Test]
    public function testAMailerFailureNeverReachesTheSenderAndLeavesTheNoticeRetryable(): void
    {
        $this->mention($this->notifier(new FailingMailer()), [$this->id('denis')]);

        self::assertNull($this->notices->find($this->conversation->id(), $this->id('denis')), 'rien n\'est resté « envoyé »');

        $mailer = new RecordingMailer();
        $this->mention($this->notifier($mailer), [$this->id('denis')], $this->now->modify('+1 minute'));
        self::assertCount(1, $mailer->sent, 'un nouvel essai part');
    }
}
