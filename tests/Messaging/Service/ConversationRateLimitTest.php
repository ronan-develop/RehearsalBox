<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Service;

use App\Account\Entity\UserRole;
use App\Account\Entity\User;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Messaging\Service\ConversationRateLimit;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class ConversationRateLimitTest extends RepositoryTestCase
{
    #[Test]
    public function testTheCheckSerializesConcurrentWritersOfTheSameAuthor(): void
    {
        $author = (new MysqlUserRepository($this->pdo))->save(new User(0, 'alice@rehearsalbox.test', 'x', 'Alice', UserRole::Musicien, true, 0, null));
        $limit = new ConversationRateLimit(new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()));
        $other = TestDatabase::connection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->pdo->beginTransaction();
        $limit->assertWithin($author->id(), new \DateTimeImmutable());

        // Tant que la transaction de l'auteur est ouverte, une seconde requête du MÊME auteur doit attendre : sinon 50 requêtes
        // parallèles lisent toutes « 29 messages » et passent toutes.
        try {
            $other->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE')->execute(['id' => $author->id()]);
            self::fail('la seconde requête aurait dû attendre le verrou de l\'auteur');
        } catch (\PDOException $e) {
            self::assertSame(1205, $e->errorInfo[1] ?? null);
        } finally {
            $this->pdo->rollBack();
        }
    }
}
