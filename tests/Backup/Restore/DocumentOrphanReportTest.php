<?php

declare(strict_types=1);

namespace App\Tests\Backup\Restore;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Backup\Restore\DocumentOrphanReport;
use App\Group\Entity\Group;
use App\Group\Entity\GroupDocument;
use App\Group\Repository\MysqlGroupDocumentRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #241 : rapport en lecture seule des écarts entre group_documents et le dossier de stockage. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DocumentOrphanReportTest extends RepositoryTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/rb-orphan-' . uniqid();
        mkdir($this->storagePath);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->storagePath);
        parent::tearDown();
    }

    #[Test]
    public function testARowWithoutItsFileIsReportedAsMissing(): void
    {
        [$group, $user] = $this->makeGroupAndUser();
        $documents = new MysqlGroupDocumentRepository($this->pdo);
        $document = $documents->save(new GroupDocument(0, $group->id(), 'fiche.pdf', 'absent.pdf', 'application/pdf', 100, $user->id()));

        $missing = (new DocumentOrphanReport($this->pdo, $this->storagePath))->missingFiles();

        self::assertSame([['id' => $document->id(), 'groupId' => $group->id(), 'originalName' => 'fiche.pdf']], $missing);
    }

    #[Test]
    public function testAccountingStatementsAreReportedTheSameWay(): void
    {
        $admin = (new MysqlUserRepository($this->pdo))->save(new User(0, 'admin@rehearsalbox.test', 'hash', 'Admin', UserRole::Admin, true, 0, null));
        $this->pdo->prepare("INSERT INTO accounting_fiscal_years (label, starts_on, ends_on, created_by_user_id, created_at) VALUES ('2025-2026', '2025-10-01', '2026-09-30', ?, '2025-10-01 00:00:00')")->execute([$admin->id()]);
        $year = (int) $this->pdo->lastInsertId();
        $insert = $this->pdo->prepare("INSERT INTO accounting_statements (fiscal_year_id, period_start, period_end, original_name, stored_name, mime, size_bytes, sha256, uploaded_by_user_id, uploaded_at) VALUES (?, '2025-10-01', '2025-10-31', ?, ?, 'application/pdf', 10, ?, ?, '2025-11-01 00:00:00')");
        $insert->execute([$year, 'present.pdf', str_repeat('a', 32), str_repeat('1', 64), $admin->id()]);
        $insert->execute([$year, 'perdu.pdf', str_repeat('b', 32), str_repeat('2', 64), $admin->id()]);
        file_put_contents($this->storagePath . '/' . str_repeat('a', 32), 'x');
        file_put_contents($this->storagePath . '/' . str_repeat('c', 32), 'x');
        $report = new DocumentOrphanReport($this->pdo, $this->storagePath, DocumentOrphanReport::ACCOUNTING_STATEMENTS);

        self::assertSame([['id' => (int) $this->pdo->query("SELECT id FROM accounting_statements WHERE original_name = 'perdu.pdf'")->fetchColumn(), 'fiscalYearId' => $year, 'originalName' => 'perdu.pdf']], $report->missingFiles());
        self::assertSame([str_repeat('c', 32)], $report->orphanFiles());
        self::assertFileExists($this->storagePath . '/' . str_repeat('c', 32), 'lecture seule : rien n\'est supprimé');
    }

    #[Test]
    public function testAFileWithoutRowIsReportedAsOrphan(): void
    {
        $this->makeGroupAndUser();
        file_put_contents($this->storagePath . '/orphelin.pdf', 'contenu');

        self::assertSame(['orphelin.pdf'], (new DocumentOrphanReport($this->pdo, $this->storagePath))->orphanFiles());
    }

    #[Test]
    public function testAConsistentStorageGivesTwoEmptyLists(): void
    {
        [$group, $user] = $this->makeGroupAndUser();
        (new MysqlGroupDocumentRepository($this->pdo))->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));
        file_put_contents($this->storagePath . '/stored-a.pdf', 'contenu');

        $report = new DocumentOrphanReport($this->pdo, $this->storagePath);

        self::assertSame([], $report->missingFiles());
        self::assertSame([], $report->orphanFiles());
    }

    #[Test]
    public function testHiddenFilesAndSubfoldersAreIgnored(): void
    {
        $this->makeGroupAndUser();
        file_put_contents($this->storagePath . '/.htaccess', 'deny');
        mkdir($this->storagePath . '/sous-dossier');
        file_put_contents($this->storagePath . '/sous-dossier/cache.pdf', 'contenu');

        self::assertSame([], (new DocumentOrphanReport($this->pdo, $this->storagePath))->orphanFiles());
    }

    #[Test]
    public function testOrphanFilesAreSortedAlphabetically(): void
    {
        $this->makeGroupAndUser();
        file_put_contents($this->storagePath . '/c.pdf', 'contenu');
        file_put_contents($this->storagePath . '/a.pdf', 'contenu');
        file_put_contents($this->storagePath . '/b.pdf', 'contenu');

        self::assertSame(['a.pdf', 'b.pdf', 'c.pdf'], (new DocumentOrphanReport($this->pdo, $this->storagePath))->orphanFiles());
    }

    #[Test]
    public function testAMissingStorageFolderGivesNoOrphanAndEveryRowIsMissing(): void
    {
        [$group, $user] = $this->makeGroupAndUser();
        $documents = new MysqlGroupDocumentRepository($this->pdo);
        $first = $documents->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));
        $second = $documents->save(new GroupDocument(0, $group->id(), 'b.pdf', 'stored-b.pdf', 'application/pdf', 100, $user->id()));
        $this->removeTree($this->storagePath);

        $report = new DocumentOrphanReport($this->pdo, $this->storagePath);

        self::assertSame([], $report->orphanFiles());
        self::assertSame(
            [$first->id(), $second->id()],
            array_column($report->missingFiles(), 'id'),
        );
    }

    #[Test]
    public function testTheReportDeletesNothing(): void
    {
        [$group, $user] = $this->makeGroupAndUser();
        $document = (new MysqlGroupDocumentRepository($this->pdo))->save(new GroupDocument(0, $group->id(), 'a.pdf', 'stored-a.pdf', 'application/pdf', 100, $user->id()));
        file_put_contents($this->storagePath . '/orphelin.pdf', 'contenu');

        $report = new DocumentOrphanReport($this->pdo, $this->storagePath);
        $report->missingFiles();
        $report->orphanFiles();

        self::assertFileExists($this->storagePath . '/orphelin.pdf');
        self::assertSame('contenu', file_get_contents($this->storagePath . '/orphelin.pdf'));
        self::assertNotNull((new MysqlGroupDocumentRepository($this->pdo))->findById($document->id()));
    }

    /** @return array{0: Group, 1: User} */
    private function makeGroupAndUser(): array
    {
        $group = (new MysqlGroupRepository($this->pdo))->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $user = (new MysqlUserRepository($this->pdo))->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        return [$group, $user];
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
