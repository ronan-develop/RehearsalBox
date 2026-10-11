<?php

declare(strict_types=1);

namespace App\Tests\Group\Service;

use App\Database\TransactionRunner;
use App\Group\Entity\GroupUserRole;
use App\Account\Entity\UserRole;
use App\Group\Entity\Group;
use App\Account\Entity\User;
use App\Group\Entity\GroupDocument;
use App\Group\Repository\GroupDocumentRepositoryInterface;
use App\Group\Repository\MysqlGroupDocumentRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Account\Repository\MysqlUserRepository;
use App\Security\Exception\AccessDeniedException;
use App\Group\Exception\InvalidUploadException;
use App\Group\Exception\StorageQuotaExceededException;
use App\Group\Service\GroupDocumentService;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
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

    private function makeService(int $maxDocuments = 20, int $maxBytes = 100 * 1024 * 1024): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $documentRepository = new MysqlGroupDocumentRepository($this->pdo);
        $service = new GroupDocumentService($documentRepository, $groupRepository, new TransactionRunner($this->pdo), $this->storagePath, $maxDocuments, $maxBytes);

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

            public function totalBytesByGroup(int $groupId): int
            {
                return $this->inner->totalBytesByGroup($groupId);
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

    /** Un PNG de $width × $height fabriqué avec GD, plus d'éventuels octets parasites à la suite. */
    private function pngBytes(int $width = 4, int $height = 3, string $trailing = ''): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean() . $trailing;
    }

    /** Un JPEG de $width × $height fabriqué avec GD ; `$segments` : segments (marqueur + contenu) insérés juste après l'en-tête SOI. */
    private function jpegBytes(int $width = 4, int $height = 3, string $segments = ''): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        return substr($jpeg, 0, 2) . $segments . substr($jpeg, 2);
    }

    /** Segment de commentaire JPEG (FFFE) : un endroit où des données parasites ou personnelles se cachent. */
    private function jpegComment(string $text): string
    {
        return "\xFF\xFE" . pack('n', strlen($text) + 2) . $text;
    }

    /** Segment EXIF (APP1) portant l'orientation $orientation (6 = à tourner de 90° dans le sens horaire). */
    private function exifOrientation(int $orientation): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08" . pack('n', 1) . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00" . "\x00\x00\x00\x00";
        $body = "Exif\x00\x00" . $tiff;

        return "\xFF\xE1" . pack('n', strlen($body) + 2) . $body;
    }

    private function uploadAsManager(GroupDocumentService $service, string $content, string $name): GroupDocument
    {
        [, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe ' . uniqid(), null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, uniqid('m') . '@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        return $service->upload($group->id(), $manager->id(), $this->fakeUploadedFile($content, $name), $name, strlen($content));
    }

    #[Test]
    public function testAPictureIsReEncodedSoTrailingBytesAndHiddenSegmentsAreDropped(): void
    {
        [$service] = $this->makeService();

        $png = $this->uploadAsManager($service, $this->pngBytes(4, 3, '<script>alert(1)</script>'), 'p.png');
        $jpeg = $this->uploadAsManager($service, $this->jpegBytes(4, 3, $this->jpegComment('POSITION-GPS-SECRETE')), 'p.jpg');

        foreach ([$png, $jpeg] as $document) {
            $stored = (string) file_get_contents($service->pathOf($document));
            self::assertStringNotContainsString('<script>', $stored);
            self::assertStringNotContainsString('POSITION-GPS-SECRETE', $stored);
            self::assertSame(['image/' . ($document === $png ? 'png' : 'jpeg'), 4, 3], [getimagesizefromstring($stored)['mime'], getimagesizefromstring($stored)[0], getimagesizefromstring($stored)[1]]);
            self::assertSame(strlen($stored), $document->sizeBytes(), 'la taille enregistrée est celle du fichier réellement stocké');
        }
    }

    #[Test]
    public function testThePhoneOrientationIsAppliedBeforeTheMetadataIsDropped(): void
    {
        [$service] = $this->makeService();

        $document = $this->uploadAsManager($service, $this->jpegBytes(4, 2, $this->exifOrientation(6)), 'portrait.jpg');

        $size = getimagesizefromstring((string) file_get_contents($service->pathOf($document)));
        self::assertSame([2, 4], [$size[0], $size[1]], 'une photo prise « debout » reste debout une fois l\'EXIF retiré');
    }

    #[Test]
    public function testAnImageWithAHugeDeclaredSizeIsRefusedWithoutBeingDecoded(): void
    {
        [$service] = $this->makeService();
        $header = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NNCCCCC', 30000, 30000, 8, 2, 0, 0, 0) . pack('N', 0);

        $this->expectException(InvalidUploadException::class);
        $this->uploadAsManager($service, $header, 'bombe.png');
    }

    #[Test]
    public function testAnImageThatCannotBeDecodedIsRefused(): void
    {
        [$service] = $this->makeService();

        $this->expectException(InvalidUploadException::class);
        $this->uploadAsManager($service, substr($this->pngBytes(), 0, 40), 'coupe.png');
    }

    #[Test]
    public function testAPdfIsStoredUntouched(): void
    {
        [$service] = $this->makeService();

        $document = $this->uploadAsManager($service, "%PDF-1.4\nbonjour", 'f.pdf');

        self::assertSame("%PDF-1.4\nbonjour", file_get_contents($service->pathOf($document)));
    }

    #[Test]
    public function testTheTotalVolumeOfAGroupIsCappedAndARefusedFileLeavesNothingOnDisk(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService(20, 1500);
        $group = $groupRepository->save(new Group(0, 'Groupe Volume', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'vol@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $pdf = '%PDF-1.4 ' . str_repeat('x', 800);

        $service->upload($group->id(), $manager->id(), $this->fakeUploadedFile($pdf, 'a.pdf'), 'a.pdf', strlen($pdf));
        try {
            $service->upload($group->id(), $manager->id(), $this->fakeUploadedFile($pdf, 'b.pdf'), 'b.pdf', strlen($pdf));
            self::fail('le deuxième fichier dépasse le volume du groupe');
        } catch (StorageQuotaExceededException $e) {
            self::assertStringContainsString('volume', $e->getMessage());
        }

        self::assertCount(1, $this->storedFiles());
    }

    #[Test]
    public function testAStoredDocumentIsReadableByTheOwnerOnly(): void
    {
        [$service, $groupRepository, $userRepository] = $this->makeService();
        $group = $groupRepository->save(new Group(0, 'Groupe Droits', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'droits@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);

        $document = $service->upload($group->id(), $manager->id(), $this->fakeUploadedFile('%PDF-1.4 x', 'a.pdf'), 'a.pdf', 10);

        self::assertSame('0600', substr(sprintf('%o', fileperms($service->pathOf($document))), -4));
    }
}
