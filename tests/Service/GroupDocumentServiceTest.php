<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Entity\GroupDocument;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\InvalidUploadException;
use App\Service\Exception\StorageQuotaExceededException;
use App\Service\GroupDocumentService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

final class GroupDocumentServiceTest extends RepositoryTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/rehearsalbox-test-' . uniqid();
        mkdir($this->storagePath, 0700, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->storagePath . '/*') ?: []);
        @rmdir($this->storagePath);
        parent::tearDown();
    }

    private function makeService(int $maxDocuments = 20): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $documentRepository = new MysqlGroupDocumentRepository($this->pdo);
        $service = new GroupDocumentService($documentRepository, $groupRepository, new TransactionRunner($this->pdo), $this->storagePath, $maxDocuments);

        return [$service, $groupRepository, $userRepository, $documentRepository];
    }

    private function createUser(MysqlUserRepository $repository, string $email): User
    {
        return $repository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: $email,
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    private function fakeUploadedFile(string $content, string $originalName): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'upload-test-');
        file_put_contents($tmpPath, $content);

        return $tmpPath;
    }

    #[Test]

    public function testUploadByGestionnaireSavesFileAndRecord(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $pdfContent = "%PDF-1.4\n%âãÏÓ\ntest content";
        $tmpPath = $this->fakeUploadedFile($pdfContent, 'fiche.pdf');

        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche technique.pdf', strlen($pdfContent));

        self::assertSame('fiche technique.pdf', $document->originalName());
        self::assertSame('application/pdf', $document->mimeType());
        self::assertFileExists($this->storagePath . '/' . $document->storedName());
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $document->storedName());
    }

    #[Test]

    public function testUploadByNonGestionnaireThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());

        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');

        $this->expectException(AccessDeniedException::class);

        $service->upload($group->id(), $member->id(), $tmpPath, 'fiche.pdf', 12);
    }

    #[Test]

    public function testUploadWithDisallowedMimeTypeThrowsInvalidUpload(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'chris@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $tmpPath = $this->fakeUploadedFile("#!/bin/sh\necho pwned", 'script.pdf');

        $this->expectException(InvalidUploadException::class);

        $service->upload($group->id(), $manager->id(), $tmpPath, 'script.pdf', 20);
    }

    #[Test]

    public function testUploadExceedingMaxSizeThrowsInvalidUpload(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'dana@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $tenMegabytesPlusOne = 10 * 1024 * 1024 + 1;

        $this->expectException(InvalidUploadException::class);

        $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', $tenMegabytesPlusOne);
    }

    #[Test]

    public function testUploadWhenQuotaReachedThrowsStorageQuotaExceeded(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService(maxDocuments: 1);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'eve@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $tmpPath1 = $this->fakeUploadedFile('%PDF-1.4 test 1', 'fiche1.pdf');
        $service->upload($group->id(), $manager->id(), $tmpPath1, 'fiche1.pdf', 20);

        $tmpPath2 = $this->fakeUploadedFile('%PDF-1.4 test 2', 'fiche2.pdf');

        $this->expectException(StorageQuotaExceededException::class);

        $service->upload($group->id(), $manager->id(), $tmpPath2, 'fiche2.pdf', 20);
    }

    #[Test]

    public function testListByMemberReturnsGroupDocuments(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'fanny@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);

        $documents = $service->listForGroup($group->id(), $manager->id());

        self::assertCount(1, $documents);
    }

    #[Test]

    public function testListByNonMemberThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $stranger = $this->createUser($userRepository, 'gaby@rehearsalbox.test');

        $this->expectException(AccessDeniedException::class);

        $service->listForGroup($group->id(), $stranger->id());
    }

    #[Test]

    public function testDeleteByGestionnaireRemovesFileAndRecord(): void
    {
        [$service, $groupRepository, $userRepository, $documentRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'hugo@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);

        $service->delete($document->id(), $manager->id());

        self::assertNull($documentRepository->findById($document->id()));
        self::assertFileDoesNotExist($this->storagePath . '/' . $document->storedName());
    }

    #[Test]

    public function testDeleteByNonGestionnaireThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'ivan@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $member = $this->createUser($userRepository, 'jade@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);

        $this->expectException(AccessDeniedException::class);

        $service->delete($document->id(), $member->id());
    }

    #[Test]

    public function testDownloadByNonMemberThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'kim@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);
        $stranger = $this->createUser($userRepository, 'liam@rehearsalbox.test');

        $this->expectException(AccessDeniedException::class);

        $service->resolveDownload($document->id(), $stranger->id());
    }

    #[Test]

    public function testDownloadByMemberResolvesTheStoredFilePath(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'mona@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);

        $resolved = $service->resolveDownload($document->id(), $manager->id());
        $path = $service->pathOf($resolved);

        self::assertFileExists($path);
        self::assertStringEndsWith($resolved->storedName(), $path);
    }

    // --- Quota atomique, nom borné, nettoyage, purge (#222) ----------------------------------------------

    /** @return array{0: GroupDocumentService, 1: int, 2: int, 3: MysqlGroupDocumentRepository} service, groupe, gestionnaire, dépôt */
    private function managerOfAGroup(int $maxDocuments = 20): array
    {
        [$service, $groupRepository, $userRepository, $documentRepository] = $this->makeService($maxDocuments);
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'zoe@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        return [$service, $group->id(), $manager->id(), $documentRepository];
    }

    private function storedFiles(): array
    {
        return array_values(array_filter(glob($this->storagePath . '/*') ?: [], 'is_file'));
    }

    #[Test]
    public function testARefusedUploadLeavesNoFileOnDisk(): void
    {
        [$service, $groupId, $managerId] = $this->managerOfAGroup(1);
        $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 un', 'a.pdf'), 'a.pdf', 11);
        self::assertCount(1, $this->storedFiles());

        try {
            $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 deux', 'b.pdf'), 'b.pdf', 13);
            self::fail('quota atteint');
        } catch (StorageQuotaExceededException) {
        }

        self::assertCount(1, $this->storedFiles(), 'le fichier du second envoi n\'est pas resté orphelin');
    }

    #[Test]
    public function testAFileIsRemovedWhenTheRecordCannotBeSaved(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'zoe@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $failing = new GroupDocumentService(new class (new MysqlGroupDocumentRepository($this->pdo)) implements GroupDocumentRepositoryInterface {
            public function __construct(private readonly GroupDocumentRepositoryInterface $inner)
            {
            }

            public function findById(int $id): ?GroupDocument
            {
                return $this->inner->findById($id);
            }

            public function findByGroup(int $groupId): array
            {
                return $this->inner->findByGroup($groupId);
            }

            public function countByGroup(int $groupId): int
            {
                return $this->inner->countByGroup($groupId);
            }

            public function lockGroupQuota(int $groupId): void
            {
                $this->inner->lockGroupQuota($groupId);
            }

            public function save(GroupDocument $document): GroupDocument
            {
                throw new \PDOException('SQLSTATE : base indisponible');
            }

            public function delete(int $id): void
            {
                $this->inner->delete($id);
            }
        }, $groupRepository, new TransactionRunner($this->pdo), $this->storagePath, 20);

        try {
            $failing->upload($group->id(), $manager->id(), $this->fakeUploadedFile('%PDF-1.4 x', 'a.pdf'), 'a.pdf', 10);
            self::fail('la base est en panne');
        } catch (\PDOException) {
        }

        self::assertSame([], $this->storedFiles(), 'aucun fichier orphelin quand l\'enregistrement échoue');
    }

    /** @return iterable<string, array{string, string}> */
    public static function nameProvider(): iterable
    {
        yield 'chemin retiré' => ['../../etc/passwd.pdf', 'passwd.pdf'];
        yield 'chemin Windows retiré' => ['C:\\Users\\x\\fiche.pdf', 'fiche.pdf'];
        yield 'caractères de contrôle retirés' => ["fi\x00che\r\n\t.pdf", 'fiche.pdf'];
        yield 'espaces rognés' => ['   plan.pdf   ', 'plan.pdf'];
        yield 'vide' => ['', 'document'];
        yield 'que des points et séparateurs' => ['../', 'document'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('nameProvider')]
    public function testTheOriginalNameIsSanitizedBeforeBeingStored(string $given, string $expected): void
    {
        [$service, $groupId, $managerId] = $this->managerOfAGroup();

        $document = $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 x', 'a.pdf'), $given, 10);

        self::assertSame($expected, $document->originalName());
    }

    #[Test]
    public function testALongNameIsBoundedButKeepsItsExtension(): void
    {
        [$service, $groupId, $managerId] = $this->managerOfAGroup();

        $document = $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 x', 'a.pdf'), str_repeat('é', 300) . '.pdf', 10);

        self::assertLessThanOrEqual(150, mb_strlen($document->originalName()));
        self::assertStringEndsWith('.pdf', $document->originalName());
        self::assertTrue(mb_check_encoding($document->originalName(), 'UTF-8'), 'jamais coupé au milieu d\'un caractère');
    }

    #[Test]
    public function testDeletingRemovesTheRecordEvenWhenTheFileIsAlreadyGone(): void
    {
        [$service, $groupId, $managerId, $documentRepository] = $this->managerOfAGroup();
        $document = $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 x', 'a.pdf'), 'a.pdf', 10);
        unlink($service->pathOf($document));

        $service->delete($document->id(), $managerId);

        self::assertNull($documentRepository->findById($document->id()));
    }

    #[Test]
    public function testTheFilesOfAGroupCanBeListedThenRemovedWhenTheGroupIsDeleted(): void
    {
        [$service, $groupId, $managerId] = $this->managerOfAGroup();
        $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 un', 'a.pdf'), 'a.pdf', 11);
        $service->upload($groupId, $managerId, $this->fakeUploadedFile('%PDF-1.4 deux', 'b.pdf'), 'b.pdf', 13);

        $paths = $service->filesOf($groupId);
        self::assertCount(2, $paths);
        $service->remove($paths);

        self::assertSame([], $this->storedFiles());
        $service->remove(['/inexistant/fichier.pdf']); // best effort : un fichier déjà absent n'est jamais une erreur
        self::addToAssertionCount(1);
    }
}
