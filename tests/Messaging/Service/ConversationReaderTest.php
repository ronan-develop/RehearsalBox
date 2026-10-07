<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Service;

use App\Messaging\Repository\ConversationRepositoryInterface as Box;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\ConversationWorld;
use PHPUnit\Framework\Attributes\Test;

/** Lecture de la messagerie : ouverture d'un fil, pastilles, archivage dérivé, polling, « vu par » et « écrit… ». */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationReaderTest extends RepositoryTestCase
{
    use ConversationWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    #[Test]
    public function testOpenFlagsTheFirstUnreadMessageBeforeMarkingTheThreadRead(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Premier');
        $this->clock->sleep(30);
        $this->reader->open($bob->id(), $conversation->id());
        $this->clock->sleep(30);
        $second = $this->service->reply($alice->id(), $conversation->id(), 'Deuxième');
        $this->service->reply($alice->id(), $conversation->id(), 'Troisième');
        $this->clock->sleep(30);

        $thread = $this->reader->open($bob->id(), $conversation->id());

        self::assertSame($second->id(), $thread->firstUnreadId(), 'le premier message reçu depuis ma dernière lecture');
        self::assertNull($this->reader->open($bob->id(), $conversation->id())->firstUnreadId(), "tout est lu à l'ouverture suivante");
        self::assertNull($this->reader->open($alice->id(), $conversation->id())->firstUnreadId(), 'mes propres messages ne comptent pas');
    }

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

        $thread = $this->reader->open($alice->id(), $conversation->id());

        self::assertSame('Alpha', $thread->authorGroup($alice->id())?->name());
        self::assertSame('Beta', $thread->authorGroup($bob->id())?->name());
        self::assertNull($thread->authorGroup($both->id()), 'membre des deux groupes : ambigu, pastille neutre');
    }

    #[Test]
    public function testAThreadSilentForMoreThanThirtyDaysIsArchivedForEveryoneAndANewMessageRevivesIt(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Message', 'Fil');
        $this->clock->modify('+31 days');

        foreach ([$alice, $bob] as $user) {
            self::assertCount(0, $this->reader->listFor($user->id(), Box::BOX_ACTIVE));
            self::assertCount(1, $this->reader->listFor($user->id(), Box::BOX_ARCHIVED));
        }

        $this->service->reply($bob->id(), $conversation->id(), 'Coucou');
        self::assertCount(1, $this->reader->listFor($alice->id(), Box::BOX_ACTIVE));
        self::assertCount(0, $this->reader->listFor($alice->id(), Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testAThreadJustUnderThirtyDaysStaysActive(): void
    {
        [$alice, , $a, $b] = $this->world();
        $this->service->start($alice->id(), $a->id(), $b->id(), 'Message');
        $this->clock->modify('+29 days 23 hours');

        self::assertCount(1, $this->reader->listFor($alice->id(), Box::BOX_ACTIVE));
    }

    #[Test]
    public function testUnreadCountCanBeSplitByBox(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $this->service->start($alice->id(), $a->id(), $b->id(), 'ancien');
        $this->clock->modify('+31 days');
        $this->service->start($alice->id(), $a->id(), $b->id(), 'récent');

        self::assertSame(2, $this->reader->unreadCount($bob->id()));
        self::assertSame(1, $this->reader->unreadCount($bob->id(), Box::BOX_ACTIVE));
        self::assertSame(1, $this->reader->unreadCount($bob->id(), Box::BOX_ARCHIVED));
    }

    #[Test]
    public function testPollReturnsOnlyNewMessagesAndMarksThemReadForTheViewer(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Premier');
        $first = $this->reader->open($bob->id(), $conversation->id())->messages()[0];
        $this->clock->sleep(10);
        $this->service->reply($alice->id(), $conversation->id(), 'Deuxième');
        self::assertSame(1, $this->reader->unreadCount($bob->id()));

        $this->clock->sleep(5);
        $update = $this->reader->poll($bob->id(), $conversation->id(), $first->id());

        self::assertSame(['Deuxième'], array_map(static fn ($m) => $m->body(), $update->messages()));
        self::assertSame(0, $this->reader->unreadCount($bob->id()), 'recevoir le message en direct = le lire');
        self::assertSame([], $this->reader->poll($bob->id(), $conversation->id(), 99999)->messages());
    }

    #[Test]
    public function testSeenByListsTheOtherMembersWhoReadMyLastMessage(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $zoe = $this->user('Zoe');
        $this->groups->addMember($b->id(), $zoe->id());
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        $before = $this->reader->poll($alice->id(), $conversation->id(), 0)->seen();
        self::assertNotNull($before);
        self::assertSame([], $before->names());
        self::assertSame(2, $before->total(), 'Bob et Zoé (hors moi)');

        $this->clock->sleep(30);
        $this->reader->open($bob->id(), $conversation->id());
        $seen = $this->reader->poll($alice->id(), $conversation->id(), 0)->seen();

        self::assertSame(['Bob'], $seen?->names());
        self::assertSame(2, $seen?->total());
    }

    #[Test]
    public function testSeenIsNullWhenIHaveNotWritten(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        self::assertNull($this->reader->poll($bob->id(), $conversation->id(), 0)->seen());
    }

    #[Test]
    public function testTypingIsVisibleToOthersForFiveSecondsOnly(): void
    {
        [$alice, $bob, $a, $b] = $this->world();
        $conversation = $this->service->start($alice->id(), $a->id(), $b->id(), 'Salut');

        $this->reader->typing($bob->id(), $conversation->id());

        self::assertSame(['Bob'], $this->reader->poll($alice->id(), $conversation->id(), 0)->typing());
        self::assertSame([], $this->reader->poll($bob->id(), $conversation->id(), 0)->typing(), 'jamais pour soi-même');
        $this->clock->sleep(6);
        self::assertSame([], $this->reader->poll($alice->id(), $conversation->id(), 0)->typing(), 'signal expiré');
    }
}
