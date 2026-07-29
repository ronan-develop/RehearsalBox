<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
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
        $service = new GroupDocumentService($documentRepository, $groupRepository, $this->storagePath, $maxDocuments);

        return [$service, $groupRepository, $userRepository, $documentRepository];
    }

    private function createUser(MysqlUserRepository $repository, string $email): User
    {
        return $repository->save(new User(
            id: 0,
            email: $email,
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
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

    public function testDownloadPathByNonMemberThrowsAccessDenied(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'kim@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);
        $stranger = $this->createUser($userRepository, 'liam@rehearsalbox.test');

        $this->expectException(AccessDeniedException::class);

        $service->resolveDownloadPath($document->id(), $stranger->id());
    }

    #[Test]

    public function testDownloadPathByMemberReturnsAbsolutePath(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'mona@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->fakeUploadedFile('%PDF-1.4 test', 'fiche.pdf');
        $document = $service->upload($group->id(), $manager->id(), $tmpPath, 'fiche.pdf', 12);

        [$path, $resolved] = [$service->resolveDownloadPath($document->id(), $manager->id()), $document];

        self::assertFileExists($path);
        self::assertStringEndsWith($resolved->storedName(), $path);
    }
}
