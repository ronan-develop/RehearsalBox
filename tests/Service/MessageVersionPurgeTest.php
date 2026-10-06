<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlMessageVersionRepository;
use App\Repository\MysqlUserRepository;
use App\Service\MessageVersionPurge;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MessageVersionPurgeTest extends RepositoryTestCase
{
    #[Test]
    public function testVersionsOlderThanTheRetentionAreForgottenTheRecentOnesAreKept(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $author = (new MysqlUserRepository($this->pdo))->save(new User(0, 'alice@rehearsalbox.test', 'x', 'Alice', UserRole::Musicien, true, 0, null));
        $groups = new MysqlGroupRepository($this->pdo);
        $a = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'));
        $b = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'));
        $conversation = (new MysqlConversationRepository($this->pdo))->create($a->id(), $b->id(), null, $now->modify('-60 days'));
        $messages = new MysqlConversationMessageRepository($this->pdo);
        $versions = new MysqlMessageVersionRepository($this->pdo);
        $message = $messages->addMessage($conversation->id(), $author->id(), 'mot de passe: secret', $now->modify('-60 days'));
        $messages->updateBody($message->id(), 'corrigé', $now->modify('-31 days'));
        $messages->updateBody($message->id(), 'corrigé encore', $now->modify('-2 days'));

        $removed = (new MessageVersionPurge($versions, new MockClock($now)))->purge();

        self::assertSame(1, $removed);
        self::assertSame(['corrigé'], array_column($versions->versionsOf($message->id()), 'body'));
    }
}
