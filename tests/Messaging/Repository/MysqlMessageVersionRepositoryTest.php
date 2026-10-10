<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Repository;

use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Messaging\Repository\MysqlMessageVersionRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlMessageVersionRepositoryTest extends RepositoryTestCase
{
    #[Test]
    public function testPurgingRemovesOnlyTheOldVersionsAndNeverTheCurrentBody(): void
    {
        $now = new \DateTimeImmutable('2026-10-06 12:00:00');
        $author = (new MysqlUserRepository($this->pdo))->save(new User(0, 'alice@rehearsalbox.test', 'x', 'Alice', UserRole::Musicien, true, 0, null));
        $groups = new MysqlGroupRepository($this->pdo);
        $a = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'));
        $b = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'));
        $conversation = (new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->create($a->id(), $b->id(), null, $now->modify('-60 days'));
        $messages = new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $versions = new MysqlMessageVersionRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $message = $messages->addMessage($conversation->id(), $author->id(), 'Texte initial', $now->modify('-60 days'));
        $messages->updateBody($message->id(), 'Première correction', $now->modify('-40 days'));
        $messages->updateBody($message->id(), 'Seconde correction', $now->modify('-1 day'));
        $messages->updateBody($message->id(), 'Texte final', $now);

        $removed = $versions->purgeBefore($now->modify('-30 days'));

        self::assertSame(1, $removed);
        self::assertSame(['Première correction', 'Seconde correction'], array_column($versions->versionsOf($message->id()), 'body'));
        self::assertSame('Texte final', $messages->messageById($conversation->id(), $message->id())->body());
    }
}
