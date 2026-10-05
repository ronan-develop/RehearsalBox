<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationMentionRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlMentionNoticeRepository;
use App\Repository\MysqlNotificationPreferenceRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationAccess;
use App\Service\ConversationMentionService;
use App\Service\ConversationService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use App\Service\MentionNotifier;
use App\Service\MessageEditService;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #200 : seul l'auteur modifie son message, pendant 15 minutes ; l'ancienne version est gardée, les mentions suivent. */
final class MessageEditServiceTest extends RepositoryTestCase
{
    private MockClock $clock;
    private ConversationService $service;
    private MessageEditService $editor;
    private MysqlConversationRepository $conversations;
    private MysqlConversationGuestRepository $guests;
    private MysqlConversationMentionRepository $mentions;
    private RecordingMailer $mailer;
    /** @var array<string, User> */
    private array $people = [];
    private int $conversationId;
    private int $messageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $this->mailer = new RecordingMailer();
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'carole', 'bob', 'denis', 'erin'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $carnage = $groups->save(new Group(0, 'Carnage', null, null, 'carnage@rehearsalbox.test'))->id();
        $groups->addMember($alpha, $this->id('alice'));
        $groups->addMember($alpha, $this->id('carole'));
        $groups->addMember($beta, $this->id('bob'));
        $groups->addMember($carnage, $this->id('denis'));
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->guests = new MysqlConversationGuestRepository($this->pdo);
        $this->mentions = new MysqlConversationMentionRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $access = new ConversationAccess($this->conversations, $groups, $this->guests);
        $mentionService = new ConversationMentionService(
            $users,
            $groups,
            $this->guests,
            $this->mentions,
            $this->conversations,
            new MentionNotifier($this->mailer, new MysqlMentionNoticeRepository($this->pdo), $users, new MysqlNotificationPreferenceRepository($this->pdo), 'no-reply@rehearsalbox.example', 'https://rehearsalbox.example'),
        );
        $this->service = new ConversationService($this->conversations, $groups, $transactions, $this->clock, mentions: $mentionService, access: $access);
        $this->editor = new MessageEditService($access, $this->conversations, $mentionService, $transactions, $this->clock);
        $this->conversationId = $this->service->start($this->id('alice'), $alpha, $beta, 'Bonjour', 'Concert')->id();
        $this->messageId = $this->conversations->messagesOf($this->conversationId)[0]->id();
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function body(): string
    {
        return $this->conversations->messageById($this->conversationId, $this->messageId)->body();
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            self::fail('AccessDeniedException attendue');
        } catch (AccessDeniedException $e) {
            self::assertSame('Accès refusé.', $e->getMessage());
        }
    }

    #[Test]
    public function testTheAuthorCorrectsTheirMessageAndTheOldVersionIsKept(): void
    {
        $this->clock->modify('+5 minutes');

        $edited = $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, '  Bonjour à tous  ');

        self::assertSame('Bonjour à tous', $edited->body(), 'même normalisation qu\'à l\'envoi');
        self::assertEquals($this->clock->now(), $edited->editedAt());
        self::assertSame(['Bonjour'], array_column($this->conversations->versionsOf($this->messageId), 'body'));
        self::assertSame('Bonjour à tous', $this->body());
    }

    #[Test]
    public function testNobodyElseCanEditNotEvenTheInitiatorOrAGroupMate(): void
    {
        $bobMessage = $this->service->reply($this->id('bob'), $this->conversationId, 'Salut de Bob')->id();

        foreach (['bob' => $this->messageId, 'carole' => $this->messageId, 'erin' => $this->messageId, 'alice' => $bobMessage] as $who => $messageId) {
            $this->denied(fn () => $this->editor->edit($this->id($who), $this->conversationId, $messageId, 'Piraté'));
        }
        self::assertSame('Bonjour', $this->body());
        self::assertSame('Salut de Bob', $this->conversations->messageById($this->conversationId, $bobMessage)->body());
    }

    #[Test]
    public function testUnknownMessagesOtherConversationsSystemLinesAndTrashedConversationsAreRefusedUniformly(): void
    {
        $other = $this->service->start($this->id('alice'), 1, 2, 'Autre fil')->id();
        $otherMessage = $this->conversations->messagesOf($other)[0]->id();
        $this->service->rename($this->id('alice'), $this->conversationId, 'Nouveau titre');
        $system = array_values(array_filter($this->conversations->messagesOf($this->conversationId), static fn ($m) => $m->isSystem()))[0]->id();

        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, 999999, 'x'));
        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, $otherMessage, 'x'));
        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, $system, 'x'));
        $this->denied(fn () => $this->editor->edit($this->id('alice'), 999999, $this->messageId, 'x'));

        $this->conversations->moveToTrash($this->conversationId, $this->clock->now());
        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, 'x'));
    }

    #[Test]
    public function testTheMessageCanBeEditedUntilFifteenMinutesAfterItWasSentThenNoMore(): void
    {
        $this->clock->modify('+15 minutes');
        $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, 'Pile dans le délai');

        $this->clock->modify('+1 second');
        try {
            $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, 'Trop tard');
            self::fail('délai dépassé');
        } catch (ConversationValidationException $e) {
            self::assertArrayHasKey('message', $e->fields());
            self::assertStringContainsString('15 minutes', $e->fields()['message']);
        }
        self::assertSame('Pile dans le délai', $this->body());
    }

    #[Test]
    public function testAnInvalidTextIsRefusedAndNothingChanges(): void
    {
        foreach (['', '   ', str_repeat('x', 5001)] as $bad) {
            try {
                $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, $bad);
                self::fail('texte refusé');
            } catch (ConversationValidationException $e) {
                self::assertArrayHasKey('message', $e->fields());
            }
        }
        self::assertSame('Bonjour', $this->body());
        self::assertSame([], $this->conversations->versionsOf($this->messageId));
    }

    #[Test]
    public function testSavingTheSameTextChangesNothing(): void
    {
        $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, ' Bonjour ');

        self::assertNull($this->conversations->messageById($this->conversationId, $this->messageId)->editedAt());
        self::assertSame([], $this->conversations->versionsOf($this->messageId));
    }

    #[Test]
    public function testAnEditCountsInTheHourlyLimit(): void
    {
        for ($i = 0; $i < ConversationService::MAX_MESSAGES_PER_HOUR - 1; $i++) {
            $this->service->reply($this->id('alice'), $this->conversationId, "m{$i}");
        }

        $this->expectException(ConversationRateLimitException::class);
        $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, 'Une de trop');
    }

    #[Test]
    public function testEditingTheSameMessageOverAndOverEventuallyHitsTheHourlyLimit(): void
    {
        $this->expectException(ConversationRateLimitException::class);

        for ($i = 1; $i <= ConversationService::MAX_MESSAGES_PER_HOUR + 1; $i++) {
            $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, "Version {$i}");
        }
    }

    #[Test]
    public function testAMentionStaysWhileItsLabelRemainsInTheTextAndGoesWhenRemoved(): void
    {
        $tagged = $this->service->reply($this->id('alice'), $this->conversationId, 'Salut @Bob', [$this->id('bob')])->id();

        $this->editor->edit($this->id('alice'), $this->conversationId, $tagged, 'Salut @Bob, ça va ?');
        self::assertSame([$this->id('bob') => '@Bob'], $this->mentions->forMessages([$tagged])[$tagged], 'la mention est conservée sans que le client renvoie l\'identifiant');

        $this->editor->edit($this->id('alice'), $this->conversationId, $tagged, 'Salut tout le monde');
        self::assertArrayNotHasKey($tagged, $this->mentions->forMessages([$tagged]), 'plus de « @Bob » dans le texte : plus de mention');
    }

    #[Test]
    public function testTaggingSomeoneNewWhileEditingRecordsItAndInvitesAnOutsiderWithoutSendingAnEmail(): void
    {
        $tagged = $this->service->reply($this->id('alice'), $this->conversationId, 'Salut', [])->id();
        $sentBefore = count($this->mailer->sent);

        $this->editor->edit($this->id('alice'), $this->conversationId, $tagged, 'Salut @Denis', [$this->id('denis')]);

        self::assertSame([$this->id('denis') => '@Denis'], $this->mentions->forMessages([$tagged])[$tagged]);
        self::assertTrue($this->guests->isGuest($this->conversationId, $this->id('denis')));
        self::assertSame($sentBefore, count($this->mailer->sent), 'une modification n\'envoie jamais d\'e-mail');
    }
}
