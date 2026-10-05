<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\Contract\ConversationRepositoryInterface as Box;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Tests\RepositoryTestCase;
use App\Tests\Support\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** Corbeille des conversations (#190) : mise à la corbeille, restauration, suppression définitive, purge. */
final class MysqlConversationTrashRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlConversationRepository $conversations;
    private MysqlConversationTrashRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->repository = new MysqlConversationTrashRepository($this->pdo);
    }

    /** @return list<string> */
    private function titles(int $userId, string $box): array
    {
        return array_map(static fn ($s) => $s->displayTitle(), $this->conversations->listFor($userId, $box, $this->cutoff));
    }

    #[Test]
    public function testATrashedThreadLeavesEveryListAndTheUnreadCountOfBothGroups(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Concert', $this->now, $alice->id());
        $this->conversations->addMessage($thread->id(), $alice->id(), 'salut', $this->now);
        self::assertSame(['Concert'], $this->titles($bob->id(), Box::BOX_ACTIVE));
        self::assertSame(1, $this->conversations->countUnreadFor($bob->id(), $this->cutoff));

        $this->repository->moveToTrash($thread->id(), $this->now);

        self::assertSame([], $this->titles($alice->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($bob->id(), Box::BOX_ACTIVE));
        self::assertSame([], $this->titles($bob->id(), Box::BOX_ARCHIVED));
        self::assertSame(0, $this->conversations->countUnreadFor($bob->id(), $this->cutoff));
        self::assertEquals($this->now, $this->conversations->findById($thread->id())->deletedAt());
    }

    #[Test]
    public function testRestoringPutsTheThreadBackEverywhere(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), 'Concert', $this->now, $alice->id());
        $this->conversations->addMessage($thread->id(), $alice->id(), 'salut', $this->now);
        $this->repository->moveToTrash($thread->id(), $this->now);

        $this->repository->restore($thread->id());

        self::assertSame(['Concert'], $this->titles($bob->id(), Box::BOX_ACTIVE));
        self::assertNull($this->conversations->findById($thread->id())->deletedAt());
    }

    #[Test]
    public function testTheTrashListsOnlyTheCreatorsOwnRecentlyTrashedThreads(): void
    {
        [$alice, $bob, $a, $b] = $this->pair();
        $carole = $this->user('Carole');
        $this->groups->addMember($a->id(), $carole->id());
        $mine = $this->conversations->create($a->id(), $b->id(), 'Récent', $this->now, $alice->id());
        $old = $this->conversations->create($a->id(), $b->id(), 'Expiré', $this->now, $alice->id());
        $theirs = $this->conversations->create($b->id(), $a->id(), 'De Bob', $this->now, $bob->id());
        $untouched = $this->conversations->create($a->id(), $b->id(), 'Actif', $this->now, $alice->id());
        foreach ([$mine, $old, $theirs, $untouched] as $thread) {
            $this->conversations->addMessage($thread->id(), $alice->id(), 'm', $this->now);
        }
        $this->repository->moveToTrash($mine->id(), $this->at('-2 days'));
        $this->repository->moveToTrash($old->id(), $this->at('-31 days'));
        $this->repository->moveToTrash($theirs->id(), $this->at('-1 day'));

        $titles = fn (int $userId): array => array_map(static fn ($s) => $s->displayTitle(), $this->repository->listTrashedBy($userId, $this->at('-30 days')));

        self::assertSame(['Récent'], $titles($alice->id()));
        self::assertSame(['De Bob'], $titles($bob->id()));
        self::assertSame([], $titles($carole->id()), "un autre membre du groupe n'y voit rien");
    }

    #[Test]
    public function testDeletingForGoodRemovesTheThreadItsMessagesAndItsStates(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $thread = $this->conversations->create($a->id(), $b->id(), null, $this->now, $alice->id());
        $this->conversations->addMessage($thread->id(), $alice->id(), 'm', $this->now);
        $this->conversations->markRead($thread->id(), $alice->id(), $this->now);

        $this->repository->delete($thread->id());

        self::assertNull($this->conversations->findById($thread->id()));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_messages')->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_states')->fetchColumn());
    }

    #[Test]
    public function testPurgeRemovesOnlyThreadsTrashedBeforeTheCutoff(): void
    {
        [$alice, , $a, $b] = $this->pair();
        $old = $this->conversations->create($a->id(), $b->id(), 'Vieux', $this->now, $alice->id());
        $recent = $this->conversations->create($a->id(), $b->id(), 'Récent', $this->now, $alice->id());
        $active = $this->conversations->create($a->id(), $b->id(), 'Actif', $this->now, $alice->id());
        $this->repository->moveToTrash($old->id(), $this->at('-31 days'));
        $this->repository->moveToTrash($recent->id(), $this->at('-5 days'));

        $purged = $this->repository->purgeTrashedBefore($this->at('-30 days'));

        self::assertSame(1, $purged);
        self::assertNull($this->conversations->findById($old->id()));
        self::assertNotNull($this->conversations->findById($recent->id()));
        self::assertNotNull($this->conversations->findById($active->id()));
    }
}
