<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationNoticeRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MysqlConversationNoticeRepositoryTest extends RepositoryTestCase
{
    private \DateTimeImmutable $now;
    private MysqlConversationNoticeRepository $notices;
    private int $conversationId;
    private int $groupA;
    private int $groupB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $this->notices = new MysqlConversationNoticeRepository($this->pdo);
        $groups = new MysqlGroupRepository($this->pdo);
        $this->groupA = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $this->groupB = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $this->conversationId = (new MysqlConversationRepository($this->pdo))->create($this->groupA, $this->groupB, null, $this->now)->id();
    }

    #[Test]
    public function testTheFirstClaimWinsAndTheSecondOneIsRefused(): void
    {
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now));
        self::assertFalse($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now->modify('+1 minute')), 'déjà prévenu : un seul e-mail');
        self::assertEquals($this->now, $this->notices->initialNotifiedAt($this->conversationId, $this->groupB), 'la date du premier envoi est conservée');
    }

    #[Test]
    public function testEachGroupHasItsOwnClaim(): void
    {
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupA, $this->now));
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now));
    }

    #[Test]
    public function testReleasingAClaimAllowsANewAttempt(): void
    {
        $this->notices->claimInitial($this->conversationId, $this->groupB, $this->now);

        $this->notices->releaseInitial($this->conversationId, $this->groupB);

        self::assertNull($this->notices->initialNotifiedAt($this->conversationId, $this->groupB));
        self::assertTrue($this->notices->claimInitial($this->conversationId, $this->groupB, $this->now->modify('+5 minutes')));
    }

    #[Test]
    public function testNoticesDisappearWithTheirConversation(): void
    {
        $this->notices->claimInitial($this->conversationId, $this->groupB, $this->now);

        $this->pdo->exec('DELETE FROM conversations WHERE id = ' . $this->conversationId);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM conversation_group_notices')->fetchColumn());
    }
}
