<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\User;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlConversationPresenceRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** Messages d'une conversation : écriture, lecture incrémentale, correction (#200) et comptages de la limite de débit. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlConversationMessageRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlConversationRepository $conversations;
    private MysqlConversationMessageRepository $messages;
    private MysqlConversationPresenceRepository $presence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->messages = new MysqlConversationMessageRepository($this->pdo);
        $this->presence = new MysqlConversationPresenceRepository($this->pdo);
    }

    // --- Édition d'un message (#200) -------------------------------------------------------------------

    /** @return array{User, User, int, int} Alice, Bob, la conversation et un message d'Alice */
    private function threadWithAMessage(): array
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now, $alice->id());
        $message = $this->messages->addMessage($thread->id(), $alice->id(), 'Texte initial', $this->at('-10 minutes'));

        return [$alice, $bob, $thread->id(), $message->id()];
    }

    #[Test]
    public function testAddMessageReturnsTheRealIncreasingIdOfTheStoredMessage(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $conversation = $this->conversations->create($a->id(), $b->id(), null, $this->now);

        $first = $this->messages->addMessage($conversation->id(), $alice->id(), 'un', $this->now);
        $second = $this->messages->addMessage($conversation->id(), $alice->id(), 'deux', $this->now);

        self::assertGreaterThan(0, $first->id());
        self::assertGreaterThan($first->id(), $second->id());
        self::assertSame([$first->id(), $second->id()], array_map(static fn ($m) => $m->id(), $this->messages->messagesOf($conversation->id())));
    }

    #[Test]
    public function testMessagesOfIsIncrementalWithAfterId(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $conversation = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $first = $this->messages->addMessage($conversation->id(), $alice->id(), 'un', $this->at('+1 minute'));
        $second = $this->messages->addMessage($conversation->id(), $alice->id(), 'deux', $this->at('+2 minutes'));
        $this->messages->addMessage($conversation->id(), $alice->id(), 'trois', $this->at('+3 minutes'));

        self::assertSame(['trois'], array_map(static fn ($m) => $m->body(), $this->messages->messagesOf($conversation->id(), $second->id())));
        self::assertSame(['deux', 'trois'], array_map(static fn ($m) => $m->body(), $this->messages->messagesOf($conversation->id(), $first->id())));
        self::assertSame([], $this->messages->messagesOf($conversation->id(), 99999));
    }

    #[Test]
    public function testMessageByIdFindsAMessageOfThatConversationOnly(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $one = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $other = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $message = $this->messages->addMessage($one->id(), $alice->id(), 'ancre', $this->now);

        self::assertSame('ancre', $this->messages->messageById($one->id(), $message->id())?->body());
        self::assertNull($this->messages->messageById($other->id(), $message->id()), 'un message d\'une autre conversation n\'est jamais renvoyé');
        self::assertNull($this->messages->messageById($one->id(), 99999));
    }

    #[Test]
    public function testSystemMessagesAreFlaggedAndOrdinaryOnesAreNot(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'Salut', $this->at('+1 minute'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'Alice a renommé la conversation', $this->at('+2 minutes'), true);

        $messages = $this->messages->messagesOf($thread->id());

        self::assertSame([false, true], array_map(static fn ($m) => $m->isSystem(), $messages));
    }

    #[Test]
    public function testLastMessageByIsTheAuthorsLastOrdinaryMessage(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'premier', $this->at('+1 minute'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'dernier ordinaire', $this->at('+2 minutes'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'Alice a renommé la conversation', $this->at('+3 minutes'), true);
        $this->messages->addMessage($thread->id(), $bob->id(), 'de Bob', $this->at('+4 minutes'));

        self::assertSame('dernier ordinaire', $this->messages->lastMessageBy($thread->id(), $alice->id())?->body());
        self::assertSame('de Bob', $this->messages->lastMessageBy($thread->id(), $bob->id())?->body());
        self::assertNull($this->messages->lastMessageBy($thread->id(), $this->user('Carol')->id()));
    }

    #[Test]
    public function testCountMessagesBySinceCountsOnlyRecentMessagesOfTheAuthor(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $this->messages->addMessage($thread->id(), $alice->id(), 'vieux', $this->at('-2 hours'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'récent', $this->at('-10 minutes'));
        $this->messages->addMessage($thread->id(), $bob->id(), 'autre', $this->now);

        self::assertSame(1, $this->messages->countMessagesBySince($alice->id(), $this->at('-1 hour')));
    }

    #[Test]
    public function testEditingReplacesTheBodyMarksTheDateAndKeepsEveryOldVersion(): void
    {
        [, , $conversationId, $messageId] = $this->threadWithAMessage();

        $this->messages->updateBody($messageId, 'Première correction', $this->at('-5 minutes'));
        $this->messages->updateBody($messageId, 'Seconde correction', $this->now);

        $message = $this->messages->messageById($conversationId, $messageId);
        self::assertSame('Seconde correction', $message->body());
        self::assertEquals($this->now, $message->editedAt());
        self::assertEquals($this->at('-10 minutes'), $message->createdAt(), 'la date d\'envoi ne bouge pas');
        self::assertSame(['Texte initial', 'Première correction'], array_column((new \App\Repository\MysqlMessageVersionRepository($this->pdo))->versionsOf($messageId), 'body'), 'chaque ancienne version est gardée, la plus ancienne d\'abord');
    }

    #[Test]
    public function testAMessageThatWasNeverEditedHasNoEditDate(): void
    {
        [, , $conversationId, $messageId] = $this->threadWithAMessage();

        self::assertNull($this->messages->messageById($conversationId, $messageId)->editedAt());
        self::assertNull($this->messages->messagesOf($conversationId)[0]->editedAt());
        self::assertSame([], (new \App\Repository\MysqlMessageVersionRepository($this->pdo))->versionsOf($messageId));
    }

    #[Test]
    public function testMessagesEditedAfterAMomentAreListedForThePollingOfTheOthers(): void
    {
        [$alice, , $conversationId, $first] = $this->threadWithAMessage();
        $second = $this->messages->addMessage($conversationId, $alice->id(), 'Deuxième', $this->at('-9 minutes'))->id();
        $third = $this->messages->addMessage($conversationId, $alice->id(), 'Troisième', $this->at('-8 minutes'))->id();
        $this->messages->updateBody($first, 'Premier corrigé', $this->at('-6 minutes'));
        $this->messages->updateBody($second, 'Deuxième corrigé', $this->at('-2 minutes'));
        $this->messages->updateBody($third, 'Troisième corrigé', $this->at('-1 minute'));

        $since = fn (string $moment, int $upTo): array => array_map(
            static fn ($m) => $m->body(),
            $this->messages->editedSince($conversationId, $this->at($moment), $upTo),
        );

        self::assertSame(['Deuxième corrigé', 'Troisième corrigé'], $since('-3 minutes', $third), 'seulement après ce moment, du plus ancien au plus récent');
        self::assertSame(['Deuxième corrigé'], $since('-3 minutes', $second), 'jamais un message que le client n\'a pas encore reçu');
        self::assertSame([], $since('-30 seconds', $third));
    }

    #[Test]
    public function testEditsAreCountedPerAuthorAndPerPeriodForTheRateLimit(): void
    {
        [$alice, $bob, $conversationId, $messageId] = $this->threadWithAMessage();
        $bobMessage = $this->messages->addMessage($conversationId, $bob->id(), 'Texte de Bob', $this->at('-10 minutes'))->id();
        $this->messages->updateBody($messageId, 'v2', $this->at('-3 hours'));
        $this->messages->updateBody($messageId, 'v3', $this->at('-10 minutes'));
        $this->messages->updateBody($messageId, 'v4', $this->at('-5 minutes'));
        $this->messages->updateBody($bobMessage, 'Bob corrigé', $this->at('-5 minutes'));

        self::assertSame(2, $this->messages->countEditsBySince($alice->id(), $this->at('-1 hour')));
        self::assertSame(3, $this->messages->countEditsBySince($alice->id(), $this->at('-1 day')));
        self::assertSame(1, $this->messages->countEditsBySince($bob->id(), $this->at('-1 hour')));
    }

    #[Test]
    public function testVersionsDisappearWithTheirConversation(): void
    {
        [, , $conversationId, $messageId] = $this->threadWithAMessage();
        $this->messages->updateBody($messageId, 'Corrigé', $this->now);

        (new MysqlConversationTrashRepository($this->pdo))->delete($conversationId);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_message_versions')->fetchColumn());
    }

    // --- Citer un message (#214) ---------------------------------------------------------------------

    #[Test]
    public function testAMessageThatQuotesAnotherCarriesAQuoteWithItsAuthorAndText(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $original = $this->messages->addMessage($thread->id(), $bob->id(), 'Jeudi à 20h ?', $this->at('-1 hour'));

        $reply = $this->messages->addMessage($thread->id(), $alice->id(), 'Oui !', $this->now, false, $original->id());

        self::assertSame($original->id(), $reply->quote()?->messageId());
        self::assertSame('Bob', $reply->quote()->authorName());
        self::assertSame('Jeudi à 20h ?', $reply->quote()->excerpt());
        $read = $this->messages->messagesOf($thread->id());
        self::assertNull($read[0]->quote(), 'le message cité ne cite rien');
        self::assertSame($original->id(), $read[1]->quote()?->messageId());
        self::assertSame($original->id(), $this->messages->messageById($thread->id(), $reply->id())->quote()?->messageId());
    }

    #[Test]
    public function testTheQuoteShowsTheCorrectedTextOnceTheQuotedMessageIsEdited(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $original = $this->messages->addMessage($thread->id(), $bob->id(), 'Jeudi à 20h ?', $this->at('-1 hour'));
        $reply = $this->messages->addMessage($thread->id(), $alice->id(), 'Oui !', $this->now, false, $original->id());

        $this->messages->updateBody($original->id(), 'Vendredi à 21h ?', $this->now);

        self::assertSame('Vendredi à 21h ?', $this->messages->messageById($thread->id(), $reply->id())->quote()->excerpt());
    }

    #[Test]
    public function testAnEditedMessageKeepsItsQuoteInThePollingOfCorrections(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);
        $original = $this->messages->addMessage($thread->id(), $bob->id(), 'Jeudi ?', $this->at('-2 hours'));
        $reply = $this->messages->addMessage($thread->id(), $alice->id(), 'Oui', $this->at('-1 hour'), false, $original->id());

        $this->messages->updateBody($reply->id(), 'Oui, avec plaisir', $this->now);

        $edited = $this->messages->editedSince($thread->id(), $this->at('-1 minute'), $reply->id());
        self::assertSame($original->id(), $edited[0]->quote()?->messageId());
    }

    #[Test]
    public function testAMessageWithoutQuoteHasNone(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now);

        $message = $this->messages->addMessage($thread->id(), $alice->id(), 'Bonjour', $this->now);

        self::assertNull($message->quote());
    }

    #[Test]
    public function testDeletingTheConversationDeletesQuotingMessagesWithoutAnError(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now, $alice->id());
        $original = $this->messages->addMessage($thread->id(), $bob->id(), 'Jeudi ?', $this->at('-1 hour'));
        $this->messages->addMessage($thread->id(), $alice->id(), 'Oui', $this->now, false, $original->id());

        (new MysqlConversationTrashRepository($this->pdo))->delete($thread->id());

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_messages')->fetchColumn());
    }
}
