<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Service;

use App\Database\TransactionRunner;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\Participation\MysqlConversationGuestRepository;
use App\Messaging\Repository\Mention\MysqlConversationMentionRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\Participation\MysqlConversationTrashRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Messaging\Repository\Notice\MysqlMentionNoticeRepository;
use App\Account\Repository\MysqlNotificationPreferenceRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Messaging\Service\ConversationAccess;
use App\Messaging\Service\Mention\ConversationMentionService;
use App\Messaging\Service\ConversationService;
use App\Messaging\Exception\ConversationRateLimitException;
use App\Messaging\Exception\ConversationValidationException;
use App\Messaging\Notification\MentionNotifier;
use App\Messaging\Service\MessageEditService;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\RecordingMailer;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use App\Tests\Scenarios\TestMailbox;

/** #200 : seul l'auteur modifie son message, pendant 15 minutes ; l'ancienne version est gardée, les mentions suivent. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MessageEditServiceTest extends RepositoryTestCase
{
    private MockClock $clock;
    private ConversationService $service;
    private MessageEditService $editor;
    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;
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
        $this->conversations = new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $this->messages = new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
        $this->guests = new MysqlConversationGuestRepository($this->pdo);
        $this->mentions = new MysqlConversationMentionRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $access = new ConversationAccess($this->conversations, $groups, $this->guests);
        $mentionService = new ConversationMentionService(
            $users,
            $groups,
            $this->guests,
            $this->mentions,
            $this->messages,
            new MentionNotifier(TestMailbox::of($this->mailer), new MysqlMentionNoticeRepository($this->pdo), $users, new MysqlNotificationPreferenceRepository($this->pdo), new \App\Messaging\Repository\Participation\MysqlConversationMuteRepository($this->pdo)),
        );
        $this->service = new ConversationService($this->conversations, $this->messages, $this->presence, $groups, $transactions, $this->clock, mentions: $mentionService, access: $access);
        $this->editor = new MessageEditService($access, $this->messages, $mentionService, $transactions, $this->clock);
        $this->conversationId = $this->service->start($this->id('alice'), $alpha, $beta, 'Bonjour', 'Concert')->id();
        $this->messageId = $this->messages->messagesOf($this->conversationId)[0]->id();
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    private function body(): string
    {
        return $this->messages->messageById($this->conversationId, $this->messageId)->body();
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
        self::assertSame(['Bonjour'], array_column((new \App\Messaging\Repository\MysqlMessageVersionRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->versionsOf($this->messageId), 'body'));
        self::assertSame('Bonjour à tous', $this->body());
    }

    #[Test]
    public function testNoTextOfTheConversationIsInClearInTheDatabaseAfterStartReplyRenameAndEdit(): void
    {
        $this->clock->modify('+5 minutes');
        $this->service->reply($this->id('bob'), $this->conversationId, 'Réponse confidentielle');
        $this->service->rename($this->id('alice'), $this->conversationId, 'Titre confidentiel');
        $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, 'Texte corrigé confidentiel');

        $dump = '';
        foreach (['SELECT title FROM conversations', 'SELECT body FROM conversation_messages', 'SELECT body FROM conversation_message_versions'] as $sql) {
            $dump .= implode('|', $this->pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN)) . '|';
        }

        foreach (['Bonjour', 'Concert', 'Réponse', 'Titre', 'corrigé', 'confidentiel', 'renommé'] as $clear) {
            self::assertStringNotContainsString($clear, $dump, "« {$clear} » est en clair en base");
        }
        self::assertSame('Texte corrigé confidentiel', $this->body(), 'et le métier relit bien le clair');
    }

    #[Test]
    public function testNobodyElseCanEditNotEvenTheInitiatorOrAGroupMate(): void
    {
        $bobMessage = $this->service->reply($this->id('bob'), $this->conversationId, 'Salut de Bob')->id();

        foreach (['bob' => $this->messageId, 'carole' => $this->messageId, 'erin' => $this->messageId, 'alice' => $bobMessage] as $who => $messageId) {
            $this->denied(fn () => $this->editor->edit($this->id($who), $this->conversationId, $messageId, 'Piraté'));
        }
        self::assertSame('Bonjour', $this->body());
        self::assertSame('Salut de Bob', $this->messages->messageById($this->conversationId, $bobMessage)->body());
    }

    #[Test]
    public function testUnknownMessagesOtherConversationsSystemLinesAndTrashedConversationsAreRefusedUniformly(): void
    {
        $other = $this->service->start($this->id('alice'), 1, 2, 'Autre fil')->id();
        $otherMessage = $this->messages->messagesOf($other)[0]->id();
        $this->service->rename($this->id('alice'), $this->conversationId, 'Nouveau titre');
        $system = array_values(array_filter($this->messages->messagesOf($this->conversationId), static fn ($m) => $m->isSystem()))[0]->id();

        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, 999999, 'x'));
        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, $otherMessage, 'x'));
        $this->denied(fn () => $this->editor->edit($this->id('alice'), $this->conversationId, $system, 'x'));
        $this->denied(fn () => $this->editor->edit($this->id('alice'), 999999, $this->messageId, 'x'));

        (new MysqlConversationTrashRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->moveToTrash($this->conversationId, $this->clock->now());
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
        self::assertSame([], (new \App\Messaging\Repository\MysqlMessageVersionRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->versionsOf($this->messageId));
    }

    #[Test]
    public function testSavingTheSameTextChangesNothing(): void
    {
        $this->editor->edit($this->id('alice'), $this->conversationId, $this->messageId, ' Bonjour ');

        self::assertNull($this->messages->messageById($this->conversationId, $this->messageId)->editedAt());
        self::assertSame([], (new \App\Messaging\Repository\MysqlMessageVersionRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->versionsOf($this->messageId));
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

    #[Test]
    public function testEditingADirectMessageWithAMentionInvitesNobodyAndSendsNoMentionEmail(): void
    {
        $users = new MysqlUserRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $direct = new \App\Messaging\Service\Direct\DirectConversationService($this->conversations, $this->messages, $this->presence, $users, $transactions, $this->clock);
        $conversation = $direct->start($this->id('alice'), $this->id('bob'), 'Salut');
        $messageId = $this->messages->messagesOf($conversation->id())[0]->id();
        $sentBefore = count($this->mailer->sent);
        $this->clock->modify('+5 minutes');

        $edited = $this->editor->edit($this->id('alice'), $conversation->id(), $messageId, 'Salut @Carole', [$this->id('carole')]);

        self::assertSame('Salut @Carole', $edited->body());
        self::assertSame(2, $this->conversations->participantCount($conversation->id()), 'personne n\'est invité');
        self::assertSame([], $this->mentions->forMessages([$messageId])[$messageId] ?? [], 'aucune mention enregistrée');
        self::assertCount($sentBefore, $this->mailer->sent, 'aucun e-mail de mention');
    }
}
