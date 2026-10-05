<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ConversationAlert;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationAlertRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationTrashRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlConversationAlertRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlConversationAlertRepository $alerts;
    private int $conversationId;
    /** @var array<string, User> */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->alerts = new MysqlConversationAlertRepository($this->pdo);
        $users = new MysqlUserRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        foreach (['alice', 'carole', 'bob', 'dave', 'erin'] as $name) {
            $this->people[$name] = $users->save(new User(0, "{$name}@rehearsalbox.test", 'hash', ucfirst($name), UserRole::Musicien, true, 0, null));
        }
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        foreach (['alice', 'carole'] as $name) {
            $groups->addMember($alpha, $this->people[$name]->id());
        }
        foreach (['bob', 'dave'] as $name) {
            $groups->addMember($beta, $this->people[$name]->id());
        }
        $this->conversationId = (new MysqlConversationRepository($this->pdo))->create($alpha, $beta, 'Secret', $this->now, $this->people['alice']->id())->id();
    }

    private function id(string $name): int
    {
        return $this->people[$name]->id();
    }

    /** @return list<string> */
    private function kindsFor(string $name, string $since = '2026-09-01 00:00:00'): array
    {
        return array_map(static fn (ConversationAlert $a): string => $a->kind(), $this->alerts->findActiveFor($this->id($name), new \DateTimeImmutable($since)));
    }

    #[Test]
    public function testEveryParticipantExceptTheActorIsNotifiedAndNobodyElse(): void
    {
        $this->alerts->notifyParticipants($this->conversationId, $this->id('alice'), ConversationAlert::DELETED, $this->now);

        self::assertSame(['deleted'], $this->kindsFor('bob'));
        self::assertSame(['deleted'], $this->kindsFor('dave'));
        self::assertSame(['deleted'], $this->kindsFor('carole'), 'son propre groupe aussi : elle perd la conversation');
        self::assertSame([], $this->kindsFor('alice'), "l'auteur de l'action n'est pas prévenu");
        self::assertSame([], $this->kindsFor('erin'), "un tiers n'apprend rien");
    }

    #[Test]
    public function testTheAlertCarriesTheGroupLabelAndNeverTheTitle(): void
    {
        $this->alerts->notifyParticipants($this->conversationId, $this->id('alice'), ConversationAlert::RESTORED, $this->now);

        $alert = $this->alerts->findActiveFor($this->id('bob'), $this->now->modify('-1 day'))[0];
        self::assertSame('Alpha ↔ Beta', $alert->label());
        self::assertSame($this->conversationId, $alert->conversationId());
        self::assertSame('restored', $alert->kind());
        self::assertStringNotContainsString('Secret', $alert->label());
    }

    #[Test]
    public function testDismissingRemovesItFromTheListAndTheCount(): void
    {
        $this->alerts->notifyParticipants($this->conversationId, $this->id('alice'), ConversationAlert::DELETED, $this->now);
        $since = $this->now->modify('-1 day');
        $alert = $this->alerts->findActiveFor($this->id('bob'), $since)[0];
        self::assertSame(1, $this->alerts->countActiveFor($this->id('bob'), $since));

        $this->alerts->dismiss($alert->id(), $this->id('bob'), $this->now);

        self::assertSame([], $this->alerts->findActiveFor($this->id('bob'), $since));
        self::assertSame(0, $this->alerts->countActiveFor($this->id('bob'), $since));
        self::assertSame(1, $this->alerts->countActiveFor($this->id('dave'), $since), "l'avis des autres reste");
    }

    #[Test]
    public function testSomeoneCannotDismissAnotherPersonsAlert(): void
    {
        $this->alerts->notifyParticipants($this->conversationId, $this->id('alice'), ConversationAlert::DELETED, $this->now);
        $alert = $this->alerts->findActiveFor($this->id('bob'), $this->now->modify('-1 day'))[0];

        $this->alerts->dismiss($alert->id(), $this->id('dave'), $this->now);

        self::assertSame(1, $this->alerts->countActiveFor($this->id('bob'), $this->now->modify('-1 day')));
    }

    #[Test]
    public function testOldAlertsAreNotListed(): void
    {
        $this->alerts->notifyParticipants($this->conversationId, $this->id('alice'), ConversationAlert::DELETED, $this->now->modify('-40 days'));

        self::assertSame([], $this->kindsFor('bob', '2026-09-06 12:00:00'));
    }

    #[Test]
    public function testTheAlertSurvivesThePurgeOfItsConversation(): void
    {
        $this->alerts->notifyParticipants($this->conversationId, $this->id('alice'), ConversationAlert::DELETED, $this->now);

        (new MysqlConversationTrashRepository($this->pdo))->delete($this->conversationId);

        $alert = $this->alerts->findActiveFor($this->id('bob'), $this->now->modify('-1 day'))[0];
        self::assertNull($alert->conversationId());
        self::assertSame('Alpha ↔ Beta', $alert->label());
    }
}
