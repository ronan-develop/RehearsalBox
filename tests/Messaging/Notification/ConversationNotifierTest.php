<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Notification;

use App\Messaging\Entity\Conversation;
use App\Group\Entity\Group;
use App\Messaging\Repository\Notice\ConversationNoticeRepositoryInterface;
use App\Messaging\Repository\Notice\MysqlConversationNoticeRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Messaging\Notification\ConversationNotifier;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FailingMailer;
use App\Tests\Doubles\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mailer\MailerInterface;
use App\Tests\Scenarios\TestMailbox;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationNotifierTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlConversationNoticeRepository $notices;
    private Group $source;
    private Group $target;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices = new MysqlConversationNoticeRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        $this->source = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'));
        $this->target = $groups->save(new Group(0, 'Beta', null, null, 'contact-beta@rehearsalbox.test'));
        $this->conversation = (new MysqlConversationRepository($this->pdo))->create($this->source->id(), $this->target->id(), 'Titre secret', $this->now);
    }

    private function notifier(MailerInterface $mailer): ConversationNotifier
    {
        return new ConversationNotifier(TestMailbox::of($mailer), $this->notices);
    }

    /** @return list<string> */
    private function recipients(\Symfony\Component\Mime\Email $email): array
    {
        return array_map(static fn ($a): string => $a->getAddress(), $email->getTo());
    }

    #[Test]
    public function testOneEmailGoesToTheContactAddressOfTheTargetGroupOnly(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->newConversation($this->conversation, 'Alice', 'Alpha', $this->target, $this->now);

        self::assertCount(1, $mailer->sent);
        self::assertSame(['contact-beta@rehearsalbox.test'], $this->recipients($mailer->sent[0]));
        self::assertSame([], $mailer->sent[0]->getCc());
        self::assertSame([], $mailer->sent[0]->getBcc());
        self::assertSame('no-reply@rehearsalbox.example', $mailer->sent[0]->getFrom()[0]->getAddress());
    }

    #[Test]
    public function testTheEmailAnnouncesTheSenderAndLinksToTheConversationWithoutAnyMessageContent(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->newConversation($this->conversation, 'Alice Martin', 'Alpha', $this->target, $this->now);
        $email = $mailer->sent[0];
        $link = 'https://rehearsalbox.example/messages/' . $this->conversation->id();

        self::assertStringContainsString('Alpha', (string) $email->getSubject());
        self::assertStringNotContainsString('Titre secret', (string) $email->getSubject());
        foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
            self::assertStringContainsString('Alice Martin', $body);
            self::assertStringContainsString('Alpha', $body);
            self::assertStringContainsString($link, $body);
            self::assertStringNotContainsString('Titre secret', $body, 'ni le titre ni le texte du message ne circulent par e-mail');
        }
    }

    #[Test]
    public function testTheGroupIsNotifiedOnlyOncePerConversation(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);

        $notifier->newConversation($this->conversation, 'Alice', 'Alpha', $this->target, $this->now);
        $notifier->newConversation($this->conversation, 'Alice', 'Alpha', $this->target, $this->now->modify('+1 minute'));

        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testAFailedSendNeverThrowsAndLeavesTheNoticeRetryable(): void
    {
        $this->notifier(new FailingMailer())->newConversation($this->conversation, 'Alice', 'Alpha', $this->target, $this->now);

        self::assertNull($this->notices->initialNotifiedAt($this->conversation->id(), $this->target->id()), 'rien de réservé : un nouvel essai reste possible');
        $mailer = new RecordingMailer();
        $this->notifier($mailer)->newConversation($this->conversation, 'Alice', 'Alpha', $this->target, $this->now->modify('+5 minutes'));
        self::assertCount(1, $mailer->sent);
    }

    #[Test]
    public function testAnyInternalFailureIsSwallowedBecauseTheMessageIsAlreadySent(): void
    {
        $brokenNotices = new class () implements ConversationNoticeRepositoryInterface {
            public function claimInitial(int $conversationId, int $groupId, \DateTimeImmutable $now): bool
            {
                throw new \PDOException('base indisponible');
            }

            public function countInitialSince(int $groupId, \DateTimeImmutable $since): int
            {
                return 0;
            }

            public function releaseInitial(int $conversationId, int $groupId): void
            {
                throw new \PDOException('base indisponible');
            }

            public function initialNotifiedAt(int $conversationId, int $groupId): ?\DateTimeImmutable
            {
                return null;
            }

            public function findDueReminders(\DateTimeImmutable $dueBefore, \DateTimeImmutable $notBefore): array
            {
                return [];
            }

            public function remindedAt(int $conversationId, int $groupId): ?\DateTimeImmutable
            {
                return null;
            }

            public function claimReminder(int $conversationId, int $groupId, \DateTimeImmutable $now): bool
            {
                return false;
            }

            public function restoreReminder(int $conversationId, int $groupId, ?\DateTimeImmutable $previous): void
            {
            }
        };
        $mailer = new RecordingMailer();

        (new ConversationNotifier(TestMailbox::of($mailer), $brokenNotices))
            ->newConversation($this->conversation, 'Alice', 'Alpha', $this->target, $this->now);

        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testAnInvalidContactAddressSendsNothingAndNeverFails(): void
    {
        $mailer = new RecordingMailer();
        $broken = new Group($this->target->id(), 'Beta', null, null, 'pas-une-adresse');

        $this->notifier($mailer)->newConversation($this->conversation, 'Alice', 'Alpha', $broken, $this->now);

        self::assertSame([], $mailer->sent);
    }

    #[Test]
    public function testTheSubjectCannotCarryAHeaderInjectionFromAGroupName(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->newConversation($this->conversation, 'Alice', "Alpha\r\nBcc: pirate@example.test", $this->target, $this->now);

        $subject = (string) $mailer->sent[0]->getSubject();
        self::assertStringNotContainsString("\n", $subject);
        self::assertStringNotContainsString("\r", $subject);
        self::assertSame([], $mailer->sent[0]->getBcc());
    }

    #[Test]
    public function testEverythingUserWrittenIsEscapedInTheHtml(): void
    {
        $mailer = new RecordingMailer();

        $this->notifier($mailer)->newConversation($this->conversation, '<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', $this->target, $this->now);

        $html = (string) $mailer->sent[0]->getHtmlBody();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    #[Test]
    public function testAGroupReceivesAtMostAFewNewConversationEmailsPerDayWhoeverWrites(): void
    {
        $mailer = new RecordingMailer();
        $notifier = $this->notifier($mailer);
        $conversations = new MysqlConversationRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        $other = $groups->save(new Group(0, 'Gamma', null, null, 'gamma@rehearsalbox.test'));

        for ($i = 0; $i < ConversationNotifier::MAX_NEW_CONVERSATIONS_PER_DAY + 3; $i++) {
            $conversation = $conversations->create($this->source->id(), $this->target->id(), null, $this->now);
            $notifier->newConversation($conversation, 'Alice', 'Alpha', $this->target, $this->now->modify("+{$i} minutes"));
        }

        self::assertCount(ConversationNotifier::MAX_NEW_CONVERSATIONS_PER_DAY, $mailer->sent, 'au-delà du plafond : plus d\'e-mail vers ce groupe');

        $conversation = $conversations->create($this->source->id(), $other->id(), null, $this->now);
        $notifier->newConversation($conversation, 'Alice', 'Alpha', $other, $this->now);
        self::assertCount(ConversationNotifier::MAX_NEW_CONVERSATIONS_PER_DAY + 1, $mailer->sent, 'un autre groupe n\'est pas concerné');

        $conversation = $conversations->create($this->source->id(), $this->target->id(), null, $this->now);
        $notifier->newConversation($conversation, 'Alice', 'Alpha', $this->target, $this->now->modify('+25 hours'));
        self::assertCount(ConversationNotifier::MAX_NEW_CONVERSATIONS_PER_DAY + 2, $mailer->sent, 'le lendemain, le plafond est levé');
    }
}
