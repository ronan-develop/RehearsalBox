<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\ConversationAlert;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\Contract\ConversationRepositoryInterface as Box;
use App\Repository\MysqlConversationAlertRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\ConversationService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #190 : l'initiateur met la conversation à la corbeille (30 jours), les participants en sont prévenus dans l'application. */
final class ConversationTrashServiceTest extends RepositoryTestCase
{
    private MockClock $clock;
    private ConversationService $service;
    private MysqlConversationRepository $conversations;
    /** @var array<string, User> */
    private array $people = [];
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-04 12:00:00');
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'carole', 'bob', 'erin'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'));
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'));
        $groups->addMember($alpha->id(), $this->people['alice']->id());
        $groups->addMember($alpha->id(), $this->people['carole']->id());
        $groups->addMember($beta->id(), $this->people['bob']->id());
        $this->conversations = new MysqlConversationRepository($this->pdo);
        $this->service = new ConversationService(
            $this->conversations,
            $groups,
            new TransactionRunner($this->pdo),
            $this->clock,
            alerts: new MysqlConversationAlertRepository($this->pdo),
        );
        $this->conversationId = $this->service->start($this->id('alice'), $alpha->id(), $beta->id(), 'Bonjour', 'Secret')->id();
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
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
    public function testTheInitiatorTrashesTheConversationAndBothGroupsLoseIt(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);

        foreach (['alice', 'carole', 'bob'] as $name) {
            self::assertSame([], $this->service->listFor($this->id($name), Box::BOX_ACTIVE), $name);
            $this->denied(fn () => $this->service->open($this->id($name), $this->conversationId));
        }
        self::assertSame(0, $this->service->unreadCount($this->id('bob')));
    }

    #[Test]
    public function testOnlyTheInitiatorCanDeleteEveryoneElseGetsTheUniformRefusal(): void
    {
        foreach (['carole' => 'même groupe', 'bob' => 'destinataire', 'erin' => 'étranger'] as $name => $why) {
            $this->denied(fn () => $this->service->delete($this->id($name), $this->conversationId));
        }
        $this->denied(fn () => $this->service->delete($this->id('alice'), 999999));
        self::assertCount(1, $this->service->listFor($this->id('bob'), Box::BOX_ACTIVE), 'rien n\'a été supprimé');
    }

    #[Test]
    public function testDeletingTwiceIsRefused(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);

        $this->denied(fn () => $this->service->delete($this->id('alice'), $this->conversationId));
    }

    #[Test]
    public function testATrashedConversationCannotBeUsedAnymore(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);

        $this->denied(fn () => $this->service->reply($this->id('bob'), $this->conversationId, 'encore là ?'));
        $this->denied(fn () => $this->service->poll($this->id('bob'), $this->conversationId, 0));
        $this->denied(fn () => $this->service->typing($this->id('bob'), $this->conversationId));
        $this->denied(fn () => $this->service->rename($this->id('bob'), $this->conversationId, 'Nouveau'));
    }

    #[Test]
    public function testEveryoneButTheActorIsWarnedInTheApplication(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);

        foreach (['carole', 'bob'] as $name) {
            $alerts = $this->service->alertsFor($this->id($name));
            self::assertCount(1, $alerts, $name);
            self::assertSame(ConversationAlert::DELETED, $alerts[0]->kind());
            self::assertSame('Alpha ↔ Beta', $alerts[0]->label());
            self::assertSame(1, $this->service->alertCount($this->id($name)));
        }
        self::assertSame([], $this->service->alertsFor($this->id('alice')));
        self::assertSame([], $this->service->alertsFor($this->id('erin')));
    }

    #[Test]
    public function testTheTrashListsTheInitiatorsConversationOnly(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);

        self::assertCount(1, $this->service->trash($this->id('alice')));
        self::assertSame([], $this->service->trash($this->id('carole')));
        self::assertSame([], $this->service->trash($this->id('bob')));
    }

    #[Test]
    public function testRestoringBringsItBackForEveryoneAndWarnsTheOthers(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);
        $this->service->restore($this->id('alice'), $this->conversationId);

        self::assertCount(1, $this->service->listFor($this->id('bob'), Box::BOX_ACTIVE));
        self::assertSame([], $this->service->trash($this->id('alice')));
        $kinds = array_map(static fn ($a) => $a->kind(), $this->service->alertsFor($this->id('bob')));
        self::assertSame([ConversationAlert::RESTORED, ConversationAlert::DELETED], $kinds);
    }

    #[Test]
    public function testOnlyTheInitiatorCanRestoreOrDeleteForGood(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);

        foreach (['carole', 'bob', 'erin'] as $name) {
            $this->denied(fn () => $this->service->restore($this->id($name), $this->conversationId));
            $this->denied(fn () => $this->service->deletePermanently($this->id($name), $this->conversationId));
        }
        self::assertCount(1, $this->service->trash($this->id('alice')));
    }

    #[Test]
    public function testRestoringOrPurgingAnActiveConversationIsRefused(): void
    {
        $this->denied(fn () => $this->service->restore($this->id('alice'), $this->conversationId));
        $this->denied(fn () => $this->service->deletePermanently($this->id('alice'), $this->conversationId));
    }

    #[Test]
    public function testDeletingForGoodRemovesItCompletely(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);
        $this->service->deletePermanently($this->id('alice'), $this->conversationId);

        self::assertNull($this->conversations->findById($this->conversationId));
        self::assertSame([], $this->service->trash($this->id('alice')));
        self::assertCount(1, $this->service->alertsFor($this->id('bob')), "l'avis reste pour prévenir");
    }

    #[Test]
    public function testAfterThirtyDaysTheTrashIsEmptiedAndRestoringIsTooLate(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);
        $this->clock->modify('+31 days');

        $this->denied(fn () => $this->service->restore($this->id('alice'), $this->conversationId));
        self::assertSame([], $this->service->trash($this->id('alice')));
        self::assertNull($this->conversations->findById($this->conversationId), 'purgée pour de bon');
    }

    #[Test]
    public function testAnAlertCanBeDismissedOnlyByItsOwner(): void
    {
        $this->service->delete($this->id('alice'), $this->conversationId);
        $alert = $this->service->alertsFor($this->id('bob'))[0];

        $this->service->dismissAlert($this->id('carole'), $alert->id());
        self::assertSame(1, $this->service->alertCount($this->id('bob')), "l'avis d'un autre ne se ferme pas");

        $this->service->dismissAlert($this->id('bob'), $alert->id());
        self::assertSame(0, $this->service->alertCount($this->id('bob')));
    }
}
