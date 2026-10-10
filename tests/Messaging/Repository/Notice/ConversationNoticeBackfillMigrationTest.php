<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Repository\Notice;

use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Messaging\Repository\Notice\MysqlConversationNoticeRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** Migration 017 : les conversations antérieures au déploiement des e-mails ne déclenchent aucune relance rétroactive. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationNoticeBackfillMigrationTest extends RepositoryTestCase
{
    private const MIGRATION = __DIR__ . '/../../../../database/migrations/017_backfill_conversation_group_notices.sql';

    #[Test]
    public function testExistingConversationsAreNeverRemindedButNewMessagesStillAre(): void
    {
        $groups = new MysqlGroupRepository($this->pdo);
        $alpha = $groups->save(new Group(0, 'Alpha', null, null, 'alpha@rehearsalbox.test'))->id();
        $beta = $groups->save(new Group(0, 'Beta', null, null, 'beta@rehearsalbox.test'))->id();
        $users = new MysqlUserRepository($this->pdo);
        $alice = $users->save(new User(0, 'alice@rehearsalbox.test', 'hash', 'Alice', UserRole::Musicien, true, 0, null));
        $groups->addMember($alpha, $alice->id());
        $conversations = new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $messages = new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make());
        $conversationId = $conversations->create($alpha, $beta, null, new \DateTimeImmutable('2026-10-01 10:00:00'))->id();
        $messages->addMessage($conversationId, $alice->id(), 'ancien message non lu', new \DateTimeImmutable('2026-10-01 10:00:00'));

        $now = new \DateTimeImmutable('2026-10-02 12:00:00');
        $notices = new MysqlConversationNoticeRepository($this->pdo);
        self::assertCount(1, $notices->findDueReminders($now->modify('-24 hours'), $now->modify('-7 days')), 'sans la migration, ce message serait relancé');

        $this->pdo->exec((string) file_get_contents(self::MIGRATION));

        self::assertSame([], $notices->findDueReminders($now->modify('-24 hours'), $now->modify('-7 days')), 'conversation antérieure : aucune relance');
        self::assertNotNull($notices->initialNotifiedAt($conversationId, $alpha), "pas d'e-mail immédiat rétroactif non plus");
        self::assertNotNull($notices->initialNotifiedAt($conversationId, $beta));

        $later = new \DateTimeImmutable('+3 days');
        $messages->addMessage($conversationId, $alice->id(), 'nouveau message', $later->modify('-2 days'));
        self::assertCount(1, $notices->findDueReminders($later->modify('-24 hours'), $later->modify('-7 days')), 'un message postérieur reste relançable');
    }
}
