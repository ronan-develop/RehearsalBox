<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\Contract\ConversationRepositoryInterface as Box;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ConversationServiceTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-04 12:00:00');
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->service = new ConversationService(
            new MysqlConversationRepository($this->pdo),
            $this->groups,
            new TransactionRunner($this->pdo),
        );
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    /** @return array{User, User, Group, Group} */
    private function world(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', $alice), $this->group('Beta', $bob)];
    }

    // --- Démarrer ------------------------------------------------------------------------------

    #[Test]
    public function testStartCreatesTheThreadWithItsFirstMessageVisibleToBothGroups(): void
    {
        [$alice, $bob, $a, $b] = $this->world();

        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), '  Créneau du jeudi ', " Salut,\nOn échange ? ", $this->now);

        self::assertSame('Créneau du jeudi', $conversation->subject());
        $thread = $this->service->open($bob->id(), $conversation->id(), $this->now);
        self::assertSame('Alpha ↔ Beta', $thread->label());
        self::assertSame(["Salut,\nOn échange ?"], array_map(static fn ($m) => $m->body(), $thread->messages()));
        self::assertSame('Alice', $thread->messages()[0]->authorName());
    }

    #[Test]
    public function testStartRefusesToSpeakForAGroupThePersonIsNotIn(): void
    {
        [$alice, , $a, $b] = $this->world();

        $this->expectException(AccessDeniedException::class);
        $this->service->start($alice->id(), $b->id(), $a->id(), 'Sujet', 'Message', $this->now);
    }

    #[Test]
    public function testStartRefusesAGroupTalkingToItselfAndAnUnknownTarget(): void
    {
        [$alice, , $a] = $this->world();

        try {
            $this->service->start($alice->id(), $a->id(), $a->id(), 'Sujet', 'Message', $this->now);
            self::fail('même groupe');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $this->service->start($alice->id(), $a->id(), 9999, 'Sujet', 'Message', $this->now);
    }

    #[Test]
    public function testStartValidatesSubjectAndMessage(): void
    {
        [$alice, , $a, $b] = $this->world();

        $cases = [
            'subject' => ['', 'Message'],
            'subject ' => [str_repeat('x', 151), 'Message'],
            'subject  ' => ["Sujet\x00caché", 'Message'],
            'message' => ['Sujet', '   '],
            'message ' => ['Sujet', str_repeat('x', 5001)],
        ];
        foreach ($cases as $field => [$subject, $body]) {
            try {
                $this->service->start($alice->id(), $a->id(), $b->id(), $subject, $body, $this->now);
                self::fail("devrait refuser ({$field})");
            } catch (ConversationValidationException $e) {
                self::assertArrayHasKey(trim($field), $e->fields());
            }
        }
        self::assertSame([], $this->service->listFor($alice->id(), Box::BOX_RECEIVED), 'rien n\'est créé en cas de refus');
    }

    #[Test]
    public function testAMessageOfExactlyTheMaximumLengthIsAccepted(): void
    {
        [$alice, , $a, $b] = $this->world();

        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), str_repeat('é', 150), str_repeat('é', 5000), $this->now);

        self::assertSame(150, mb_strlen($conversation->subject()));
    }

    // --- Accès : lire / répondre / archiver ------------------------------------------------

    #[Test]
    public function testAnOutsiderGetsTheSameRefusalAsForAMissingThread(): void
    {
        [$alice, , $a, $b] = $this->world();
        $outsider = $this->user('Carol');
        $this->group('Gamma', $outsider);
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Sujet', 'Message', $this->now);

        $calls = [
            fn () => $this->service->open($outsider->id(), $conversation->id(), $this->now),
            fn () => $this->service->reply($outsider->id(), $conversation->id(), 'Intrus', $this->now),
            fn () => $this->service->archive($outsider->id(), $conversation->id(), true),
            fn () => $this->service->open($alice->id(), 9999, $this->now),
        ];
        $messages = [];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('devrait être refusé');
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages), 'messages indiscernables');
        self::assertCount(1, $this->service->open($alice->id(), $conversation->id(), $this->now)->messages(), 'aucun message ajouté');
    }

    #[Test]
    public function testAnyMemberOfEitherGroupCanReplyAndTheReplyIsUnreadForTheOthers(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $drummer = $this->user('Dave');
        $this->groups->addMember($a->id(), $drummer->id());
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Sujet', 'Message', $this->now);

        $this->service->reply($bob->id(), $conversation->id(), 'Réponse de Bob', $this->now->modify('+1 minute'));

        self::assertSame(1, $this->service->unreadCount($drummer->id()), 'un autre membre du groupe A voit le non-lu');
        self::assertSame(1, $this->service->unreadCount($alice->id()));
        self::assertSame(0, $this->service->unreadCount($bob->id()));
        $this->service->open($drummer->id(), $conversation->id(), $this->now->modify('+2 minutes'));
        self::assertSame(0, $this->service->unreadCount($drummer->id()), 'ouvrir le fil le marque lu');
        self::assertSame(1, $this->service->unreadCount($alice->id()), 'la lecture est par personne');
    }

    #[Test]
    public function testReplyValidatesTheMessage(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Sujet', 'Message', $this->now);

        $this->expectException(ConversationValidationException::class);
        $this->service->reply($alice->id(), $conversation->id(), "  \n ", $this->now);
    }

    #[Test]
    public function testArchivingIsPerPersonAndReversible(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Sujet', 'Message', $this->now);

        $this->service->archive($bob->id(), $conversation->id(), true);

        self::assertCount(1, $this->service->listFor($bob->id(), Box::BOX_ARCHIVED));
        self::assertCount(1, $this->service->listFor($alice->id(), Box::BOX_RECEIVED));
        $this->service->archive($bob->id(), $conversation->id(), false);
        self::assertCount(1, $this->service->listFor($bob->id(), Box::BOX_RECEIVED));
    }

    #[Test]
    public function testALeftGroupMemberLosesAccess(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Sujet', 'Message', $this->now);
        $this->groups->removeMember($b->id(), $bob->id());

        $this->expectException(AccessDeniedException::class);
        $this->service->open($bob->id(), $conversation->id(), $this->now);
    }

    // --- Limite d'envois -----------------------------------------------------------------------

    #[Test]
    public function testSendingIsLimitedPerHourAndPerAuthor(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Sujet', 'Message 1', $this->now);
        for ($i = 2; $i <= ConversationService::MAX_MESSAGES_PER_HOUR; $i++) {
            $this->service->reply($alice->id(), $conversation->id(), "Message {$i}", $this->now);
        }

        try {
            $this->service->reply($alice->id(), $conversation->id(), 'De trop', $this->now);
            self::fail('limite attendue');
        } catch (ConversationRateLimitException) {
        }

        self::assertCount(ConversationService::MAX_MESSAGES_PER_HOUR, $this->service->open($alice->id(), $conversation->id(), $this->now)->messages());
        $this->service->reply($bob->id(), $conversation->id(), 'Bob peut répondre', $this->now);
        $this->service->reply($alice->id(), $conversation->id(), 'Une heure plus tard', $this->now->modify('+61 minutes'));
        $this->addToAssertionCount(1);
    }
}
