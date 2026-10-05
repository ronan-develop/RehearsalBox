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
use Symfony\Component\Clock\MockClock;

final class ConversationServiceTest extends RepositoryTestCase
{
    private MockClock $clock;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private ConversationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->service = new ConversationService(
            new MysqlConversationRepository($this->pdo),
            $this->groups,
            new TransactionRunner($this->pdo),
            $this->clock,
        );
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, ?string $color, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, $color, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    /** @return array{User, User, Group, Group} Alice (Alpha) et Bob (Beta) */
    private function world(): array
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');

        return [$alice, $bob, $this->group('Alpha', '#aa0000', $alice), $this->group('Beta', '#0000aa', $bob)];
    }

    /** @param list<\App\Entity\ConversationMessage> $messages */
    private function lastOf(array $messages): \App\Entity\ConversationMessage
    {
        return $messages[array_key_last($messages)];
    }

    // --- Démarrer ------------------------------------------------------------------------------

    #[Test]
    public function testStartWithoutTitleShowsTheLabelOfBothGroupsToBothSides(): void
    {
        [$alice, $bob, $a, $b] = $this->world();

        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), " Salut,\nOn échange ? ");

        self::assertNull($conversation->title());
        $thread = $this->service->open($bob->id(), $conversation->id());
        self::assertSame('Alpha ↔ Beta', $thread->displayTitle());
        self::assertSame(["Salut,\nOn échange ?"], array_map(static fn ($m) => $m->body(), $thread->messages()));
        self::assertSame('Alice', $thread->messages()[0]->authorName());
    }

    #[Test]
    public function testStartWithATitleTrimsItAndBlankMeansNoTitle(): void
    {
        [$alice, , $a, $b] = $this->world();

        $titled = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut', '  Concert du 12 ');
        $blank = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut', '   ');

        self::assertSame('Concert du 12', $titled->title());
        self::assertNull($blank->title());
    }

    #[Test]
    public function testStartRefusesToSpeakForAGroupThePersonIsNotInTalkToItselfOrToAnUnknownGroup(): void
    {
        [$alice, , $a, $b] = $this->world();
        $messages = [];

        foreach ([[$b->id(), $a->id()], [$a->id(), $a->id()], [$a->id(), 9999]] as [$from, $to]) {
            try {
                $this->service->start($alice->id(), $from, $to, 'Message');
                self::fail('refus attendu');
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertCount(1, array_unique($messages), 'refus indiscernables');
        self::assertSame([], $this->service->listFor($alice->id(), Box::BOX_ACTIVE));
    }

    #[Test]
    public function testStartValidatesTitleAndMessageAndCreatesNothingOnRefusal(): void
    {
        [$alice, , $a, $b] = $this->world();

        $cases = [
            'message' => ['   ', null],
            'message ' => [str_repeat('x', 5001), null],
            'title' => ['Message', str_repeat('x', 151)],
            'title ' => ['Message', "Titre\x00caché"],
        ];
        foreach ($cases as $field => [$body, $title]) {
            try {
                $this->service->start($alice->id(), $a->id(), $b->id(), $body, $title);
                self::fail("devrait refuser ({$field})");
            } catch (ConversationValidationException $e) {
                self::assertArrayHasKey(trim($field), $e->fields());
            }
        }
        self::assertSame([], $this->service->listFor($alice->id(), Box::BOX_ACTIVE), 'rien n\'est créé en cas de refus');
    }

    #[Test]
    public function testMaximumLengthsAreAccepted(): void
    {
        [$alice, , $a, $b] = $this->world();

        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), str_repeat('é', 5000), str_repeat('é', 150));

        self::assertSame(150, mb_strlen((string) $conversation->title()));
    }

    // --- Accès ------------------------------------------------------------------------------------

    #[Test]
    public function testAnOutsiderGetsTheSameRefusalAsForAMissingThread(): void
    {
        [$alice, , $a, $b] = $this->world();
        $outsider = $this->user('Carol');
        $this->group('Gamma', null, $outsider);
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');

        $calls = [
            fn () => $this->service->open($outsider->id(), $conversation->id()),
            fn () => $this->service->poll($outsider->id(), $conversation->id(), 0),
            fn () => $this->service->reply($outsider->id(), $conversation->id(), 'Intrus'),
            fn () => $this->service->rename($outsider->id(), $conversation->id(), 'Piraté'),
            fn () => $this->service->typing($outsider->id(), $conversation->id()),
            fn () => $this->service->open($alice->id(), 9999),
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
        $thread = $this->service->open($alice->id(), $conversation->id());
        self::assertCount(1, $thread->messages(), 'aucun message ajouté');
        self::assertNull($thread->conversation()->title(), 'aucun renommage');
    }

    #[Test]
    public function testAMemberWhoLeftTheGroupLosesAccess(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->groups->removeMember($b->id(), $bob->id());

        $this->expectException(AccessDeniedException::class);
        $this->service->open($bob->id(), $conversation->id());
    }

    // --- Répondre, lecture, non lu --------------------------------------------------------------

    #[Test]
    public function testAnyMemberOfEitherGroupCanReplyAndTheReplyIsUnreadForTheOthers(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $drummer = $this->user('Dave');
        $this->groups->addMember($a->id(), $drummer->id());
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->clock->sleep(60);

        $this->service->reply($bob->id(), $conversation->id(), 'Réponse de Bob');

        self::assertSame(1, $this->service->unreadCount($drummer->id()), 'un autre membre du groupe A voit le non-lu');
        self::assertSame(1, $this->service->unreadCount($alice->id()));
        self::assertSame(0, $this->service->unreadCount($bob->id()), 'répondre suppose avoir lu');
        $this->clock->sleep(60);
        $this->service->open($drummer->id(), $conversation->id());
        self::assertSame(0, $this->service->unreadCount($drummer->id()), 'ouvrir le fil le marque lu');
        self::assertSame(1, $this->service->unreadCount($alice->id()), 'la lecture est par personne');
    }

    #[Test]
    public function testReplyValidatesTheMessage(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');

        $this->expectException(ConversationValidationException::class);
        $this->service->reply($alice->id(), $conversation->id(), "  \n ");
    }

    // --- Pastille : groupe de l'auteur -------------------------------------------------------------

    #[Test]
    public function testEachAuthorIsAttachedToTheirGroupUnlessAmbiguous(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $both = $this->user('Zoe');
        $this->groups->addMember($a->id(), $both->id());
        $this->groups->addMember($b->id(), $both->id());
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->service->reply($bob->id(), $conversation->id(), 'Bob');
        $this->service->reply($both->id(), $conversation->id(), 'Zoé');

        $thread = $this->service->open($alice->id(), $conversation->id());

        self::assertSame('Alpha', $thread->authorGroup($alice->id())?->name());
        self::assertSame('Beta', $thread->authorGroup($bob->id())?->name());
        self::assertNull($thread->authorGroup($both->id()), 'membre des deux groupes : ambigu, pastille neutre');
    }

    // --- Titre ----------------------------------------------------------------------------------------

    #[Test]
    public function testAnyMemberCanRenameAndTheFilGetsASystemLine(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->clock->sleep(60);

        $this->service->rename($bob->id(), $conversation->id(), '  Concert du 12 ');

        self::assertSame(1, $this->service->unreadCount($alice->id()), 'les autres voient le renommage');
        $thread = $this->service->open($alice->id(), $conversation->id());
        self::assertSame('Concert du 12', $thread->displayTitle());
        $system = $this->lastOf($thread->messages());
        self::assertTrue($system->isSystem());
        self::assertSame('Bob', $system->authorName());
        self::assertSame('a renommé la conversation « Concert du 12 »', $system->body());
    }

    #[Test]
    public function testRemovingTheTitleRestoresTheLabelAndRenamingToTheSameTitleIsANoOp(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message', 'Concert');

        $this->service->rename($alice->id(), $conversation->id(), 'Concert');
        self::assertCount(1, $this->service->open($alice->id(), $conversation->id())->messages(), 'même titre : aucune ligne');

        $this->service->rename($alice->id(), $conversation->id(), '  ');
        $thread = $this->service->open($alice->id(), $conversation->id());
        self::assertSame('Alpha ↔ Beta', $thread->displayTitle());
        self::assertSame('a retiré le titre de la conversation', $this->lastOf($thread->messages())->body());
    }

    #[Test]
    public function testRenameValidatesTheTitle(): void
    {
        [$alice, , $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');

        try {
            $this->service->rename($alice->id(), $conversation->id(), str_repeat('x', 151));
            self::fail('refus attendu');
        } catch (ConversationValidationException $e) {
            self::assertArrayHasKey('title', $e->fields());
        }
        self::assertNull($this->service->open($alice->id(), $conversation->id())->conversation()->title());
    }

    // --- Archivage dérivé de l'inactivité ----------------------------------------------------------------

    #[Test]
    public function testAThreadSilentForMoreThanThirtyDaysIsArchivedForEveryoneAndANewMessageRevivesIt(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message', 'Fil');
        $this->clock->modify('+31 days');

        foreach ([$alice, $bob] as $user) {
            self::assertCount(0, $this->service->listFor($user->id(), Box::BOX_ACTIVE));
            self::assertCount(1, $this->service->listFor($user->id(), Box::BOX_ARCHIVED));
        }

        $this->service->reply($bob->id(), $conversation->id(), 'Coucou');
        self::assertCount(1, $this->service->listFor($alice->id(), Box::BOX_ACTIVE));
        self::assertCount(0, $this->service->listFor($alice->id(), Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testAThreadJustUnderThirtyDaysStaysActive(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->clock->modify('+29 days 23 hours');

        self::assertCount(1, $this->service->listFor($alice->id(), Box::BOX_ACTIVE));
    }

    #[Test]
    public function testUnreadCountCanBeSplitByBox(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $this->service->start($alice->id(), $a->id(), $b->id(), 'ancien');
        $this->clock->modify('+31 days');
        $this->service->start($alice->id(), $a->id(), $b->id(), 'récent');

        self::assertSame(2, $this->service->unreadCount($bob->id()));
        self::assertSame(1, $this->service->unreadCount($bob->id(), Box::BOX_ACTIVE));
        self::assertSame(1, $this->service->unreadCount($bob->id(), Box::BOX_ARCHIVED));
    }

    // --- Quasi temps réel : poll, vu par, écrit… ------------------------------------------------------

    #[Test]
    public function testPollReturnsOnlyNewMessagesAndMarksThemReadForTheViewer(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Premier');
        $first = $this->service->open($bob->id(), $conversation->id())->messages()[0];
        $this->clock->sleep(10);
        $this->service->reply($alice->id(), $conversation->id(), 'Deuxième');
        self::assertSame(1, $this->service->unreadCount($bob->id()));

        $this->clock->sleep(5);
        $update = $this->service->poll($bob->id(), $conversation->id(), $first->id());

        self::assertSame(['Deuxième'], array_map(static fn ($m) => $m->body(), $update->messages()));
        self::assertSame(0, $this->service->unreadCount($bob->id()), 'recevoir le message en direct = le lire');
        self::assertSame([], $this->service->poll($bob->id(), $conversation->id(), 99999)->messages());
    }

    #[Test]
    public function testSeenByListsTheOtherMembersWhoReadMyLastMessage(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $zoe = $this->user('Zoe');
        $this->groups->addMember($b->id(), $zoe->id());
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        $before = $this->service->poll($alice->id(), $conversation->id(), 0)->seen();
        self::assertNotNull($before);
        self::assertSame([], $before->names());
        self::assertSame(2, $before->total(), 'Bob et Zoé (hors moi)');

        $this->clock->sleep(30);
        $this->service->open($bob->id(), $conversation->id());
        $seen = $this->service->poll($alice->id(), $conversation->id(), 0)->seen();

        self::assertSame(['Bob'], $seen?->names());
        self::assertSame(2, $seen?->total());
    }

    #[Test]
    public function testSeenIsNullWhenIHaveNotWritten(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        self::assertNull($this->service->poll($bob->id(), $conversation->id(), 0)->seen());
    }

    #[Test]
    public function testTypingIsVisibleToOthersForFiveSecondsOnly(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        $this->service->typing($bob->id(), $conversation->id());

        self::assertSame(['Bob'], $this->service->poll($alice->id(), $conversation->id(), 0)->typing());
        self::assertSame([], $this->service->poll($bob->id(), $conversation->id(), 0)->typing(), 'jamais pour soi-même');
        $this->clock->sleep(6);
        self::assertSame([], $this->service->poll($alice->id(), $conversation->id(), 0)->typing(), 'signal expiré');
    }

    // --- Limite d'envois ------------------------------------------------------------------------------

    #[Test]
    public function testSendingIsLimitedPerHourAndPerAuthor(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message 1');
        for ($i = 2; $i <= ConversationService::MAX_MESSAGES_PER_HOUR; $i++) {
            $this->service->reply($alice->id(), $conversation->id(), "Message {$i}");
        }

        try {
            $this->service->reply($alice->id(), $conversation->id(), 'De trop');
            self::fail('limite attendue');
        } catch (ConversationRateLimitException) {
        }
        try {
            $this->service->start($alice->id(), $a->id(), $b->id(), 'Encore');
            self::fail('limite attendue aussi pour un nouveau fil');
        } catch (ConversationRateLimitException) {
        }

        $this->service->reply($bob->id(), $conversation->id(), 'Bob peut répondre');
        $this->clock->sleep(3601);
        $this->service->reply($alice->id(), $conversation->id(), 'Une heure plus tard');
        $this->addToAssertionCount(1);
    }
}
