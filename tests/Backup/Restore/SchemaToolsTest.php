<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Backup\Restore\SchemaTools;
use App\Tests\Database\TestDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #241 : vider / inspecter un schéma, sans jamais toucher un autre. Schéma dédié, créé puis supprimé par le test. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class SchemaToolsTest extends TestCase
{
    private const SCHEMA = 'rehearsalbox_schematools_test';
    private const OTHER = 'rehearsalbox_schematools_other';

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();
        foreach ([self::SCHEMA, self::OTHER] as $schema) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
            $this->pdo->exec("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4");
        }
        $this->pdo->exec('CREATE TABLE `' . self::SCHEMA . '`.parent (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE `' . self::SCHEMA . '`.child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY (parent_id) REFERENCES parent(id)) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE `' . self::OTHER . '`.keep (id INT PRIMARY KEY) ENGINE=InnoDB');
    }

    protected function tearDown(): void
    {
        foreach ([self::SCHEMA, self::OTHER] as $schema) {
            $this->pdo->exec("DROP DATABASE IF EXISTS `{$schema}`");
        }
    }

    #[Test]
    public function testItListsTheTablesOfASchemaAndKnowsWhenItIsEmpty(): void
    {
        $tools = new SchemaTools($this->pdo);

        self::assertSame(['child', 'parent'], $tools->tables(self::SCHEMA));
        self::assertFalse($tools->isEmpty(self::SCHEMA));
        $this->pdo->exec('DROP DATABASE `' . self::OTHER . '`');
        $this->pdo->exec('CREATE DATABASE `' . self::OTHER . '`');
        self::assertTrue($tools->isEmpty(self::OTHER));
    }

    #[Test]
    public function testDroppingEveryTableHandlesForeignKeysAndLeavesOtherSchemasAlone(): void
    {
        $tools = new SchemaTools($this->pdo);

        self::assertSame(2, $tools->dropAllTables(self::SCHEMA));

        self::assertTrue($tools->isEmpty(self::SCHEMA));
        self::assertSame(['keep'], $tools->tables(self::OTHER));
        self::assertSame('1', (string) $this->pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn(), 'contrôle des clés rétabli');
    }

    #[Test]
    public function testASchemaNameThatIsNotAPlainIdentifierIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SchemaTools($this->pdo))->dropAllTables('base`; DROP DATABASE x; --');
    }
}
