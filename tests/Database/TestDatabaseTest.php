<?php

declare(strict_types=1);

namespace App\Tests\Database;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #207 : la base de test est préparée UNE fois par exécution (schéma), puis seules les tables touchées sont vidées entre
 * deux tests, compteurs d'identifiants remis à zéro. Ces tests protègent ce mécanisme, dont dépend toute la suite.
 */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class TestDatabaseTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../database/migrations';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        TestDatabase::reset($this->pdo); // invalide aussi l'état « schéma prêt »
    }

    protected function tearDown(): void
    {
        TestDatabase::reset($this->pdo); // laisse le champ libre : le test suivant reconstruit proprement
    }

    private function insertUser(string $email): int
    {
        $this->pdo->prepare("INSERT INTO users (email, password_hash, display_name, role, is_active) VALUES (?, 'x', 'N', 'musicien', 1)")->execute([$email]);

        return (int) $this->pdo->lastInsertId();
    }

    private function migrationsLogCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM migrations_log')->fetchColumn();
    }

    #[Test]
    public function testTheFirstPreparationBuildsTheWholeSchema(): void
    {
        TestDatabase::fresh($this->pdo, self::MIGRATIONS);

        self::assertSame(count(glob(self::MIGRATIONS . '/*.sql')), $this->migrationsLogCount());
        self::assertSame(1, $this->insertUser('a@rehearsalbox.test'), 'base vide : les identifiants démarrent à 1');
    }

    #[Test]
    public function testLaterPreparationsEmptyTheDataButDoNotRebuildTheSchema(): void
    {
        TestDatabase::fresh($this->pdo, self::MIGRATIONS);
        $this->insertUser('a@rehearsalbox.test');
        $this->pdo->exec("INSERT INTO migrations_log (migration) VALUES ('zz_marqueur.sql')");

        TestDatabase::fresh($this->pdo, self::MIGRATIONS);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'les données sont vidées');
        self::assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM migrations_log WHERE migration = 'zz_marqueur.sql'")->fetchColumn(), 'le schéma et son journal ne sont pas reconstruits');
    }

    #[Test]
    public function testIdentifiersRestartAtOneEvenWhenTheTableWasEmptiedByTheTest(): void
    {
        TestDatabase::fresh($this->pdo, self::MIGRATIONS);
        $this->insertUser('a@rehearsalbox.test');
        $this->insertUser('b@rehearsalbox.test');
        $this->pdo->exec('DELETE FROM users'); // table vide, mais le compteur a avancé

        TestDatabase::fresh($this->pdo, self::MIGRATIONS);

        self::assertSame(1, $this->insertUser('c@rehearsalbox.test'));
    }

    #[Test]
    public function testTablesWithoutAnAutoIncrementColumnAreEmptiedToo(): void
    {
        TestDatabase::fresh($this->pdo, self::MIGRATIONS);
        $user = $this->insertUser('a@rehearsalbox.test');
        $this->pdo->exec("INSERT INTO `groups` (name, contact_email) VALUES ('G', 'g@rehearsalbox.test')");
        $group = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO group_user (group_id, user_id) VALUES (?, ?)')->execute([$group, $user]);

        TestDatabase::fresh($this->pdo, self::MIGRATIONS);

        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM group_user')->fetchColumn());
    }

    #[Test]
    public function testAFullResetForcesTheNextPreparationToRebuildTheSchema(): void
    {
        TestDatabase::fresh($this->pdo, self::MIGRATIONS);
        $this->pdo->exec("INSERT INTO migrations_log (migration) VALUES ('zz_marqueur.sql')");

        TestDatabase::reset($this->pdo);
        TestDatabase::fresh($this->pdo, self::MIGRATIONS);

        self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM migrations_log WHERE migration = 'zz_marqueur.sql'")->fetchColumn(), 'schéma reconstruit');
        self::assertSame(count(glob(self::MIGRATIONS . '/*.sql')), $this->migrationsLogCount());
    }
}
