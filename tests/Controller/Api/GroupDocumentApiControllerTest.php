<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\GroupDocumentApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Tests\Support\FastPasswordHasher;
use App\Service\AuthService;
use App\Service\GroupDocumentService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\KernelTranslation;
use App\Security\Exception\AccessDeniedException;
use PHPUnit\Framework\Attributes\Test;

final class GroupDocumentApiControllerTest extends RepositoryTestCase
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

    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);
        $documentRepository = new MysqlGroupDocumentRepository($this->pdo);
        $documentService = new GroupDocumentService($documentRepository, $groupRepository, new TransactionRunner($this->pdo), $this->storagePath, 20);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new FastPasswordHasher(), $session, $groupRepository);
        $authGuard = new AuthGuard($authService);

        $controller = new KernelTranslation(new GroupDocumentApiController($documentService, $authGuard));

        return [$controller, $groupRepository, $userRepository, $authService, $documentService];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(
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

    private function uploadedTmpFile(string $content): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'upload-test-');
        file_put_contents($tmpPath, $content);

        return $tmpPath;
    }

    #[Test]

    public function testStoreByGestionnaireReturns201(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'alice@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('alice@rehearsalbox.test', 'password');

        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $request = new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files);

        $response = $controller->store($request, (string) $group->id());

        self::assertSame(201, $response->statusCode());
    }

    #[Test]

    public function testStoreByNonGestionnaireIsRefused(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $request = new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files);

        $this->expectException(AccessDeniedException::class);
        $controller->store($request, (string) $group->id());
    }

    #[Test]

    public function testStoreWithDisallowedMimeReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'chris@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('chris@rehearsalbox.test', 'password');

        $tmpPath = $this->uploadedTmpFile("#!/bin/sh\necho pwned");
        $files = ['document' => ['name' => 'script.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $request = new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files);

        $response = $controller->store($request, (string) $group->id());

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testStoreWithoutFileReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'dana@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('dana@rehearsalbox.test', 'password');

        $request = new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], []);

        $response = $controller->store($request, (string) $group->id());

        self::assertSame(422, $response->statusCode());
    }

    #[Test]

    public function testIndexByMemberReturns200WithDocumentList(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'eve@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('eve@rehearsalbox.test', 'password');
        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());

        $response = $controller->index(new Request('GET', "/api/groups/{$group->id()}/documents", [], [], []), (string) $group->id());
        $body = json_decode($response->body(), true);

        self::assertSame(200, $response->statusCode());
        self::assertCount(1, $body['documents']);
    }

    #[Test]

    public function testIndexByNonMemberIsRefused(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'fanny@rehearsalbox.test');
        $authService->attempt('fanny@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);
        $controller->index(new Request('GET', "/api/groups/{$group->id()}/documents", [], [], []), (string) $group->id());
    }

    #[Test]

    public function testDownloadByMemberReturns200WithFileContent(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('gaby@rehearsalbox.test', 'password');
        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $storeResponse = $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());
        $documentId = json_decode($storeResponse->body(), true)['id'];

        $response = $controller->download(new Request('GET', "/api/documents/{$documentId}", [], [], []), (string) $documentId);

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('%PDF-1.4', $response->body());
    }

    #[Test]

    public function testDownloadOfAPdfIsAnAttachmentWithNoSniffingAndASanitizedFilename(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('gaby@rehearsalbox.test', 'password');
        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => "fiche \"été\"\r\nX-Evil: 1.pdf", 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $storeResponse = $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());
        $documentId = json_decode($storeResponse->body(), true)['id'];

        $headers = $controller->download(new Request('GET', "/api/documents/{$documentId}", [], [], []), (string) $documentId)->headers();

        self::assertSame('nosniff', $headers['X-Content-Type-Options'] ?? null);
        $disposition = $headers['Content-Disposition'] ?? '';
        self::assertStringStartsWith('attachment; filename="', $disposition);
        self::assertStringContainsString("filename*=UTF-8''", $disposition);
        // Pas d'injection d'en-tête ni de guillemet non échappé via le nom d'origine.
        self::assertStringNotContainsString("\r", $disposition);
        self::assertStringNotContainsString("\n", $disposition);
        self::assertSame(1, preg_match('/^attachment; filename="[^"\\\r\n]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+$/', $disposition));
    }

    #[Test]

    public function testDownloadByNonMemberIsRefused(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'hugo@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $authService->attempt('hugo@rehearsalbox.test', 'password');
        $storeResponse = $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());
        $documentId = json_decode($storeResponse->body(), true)['id'];

        $stranger = $this->createUser($userRepository, 'ivan@rehearsalbox.test');
        $authService->attempt('ivan@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);
        $controller->download(new Request('GET', "/api/documents/{$documentId}", [], [], []), (string) $documentId);
    }

    #[Test]

    public function testDestroyByGestionnaireReturns204(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'jade@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('jade@rehearsalbox.test', 'password');
        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $storeResponse = $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());
        $documentId = json_decode($storeResponse->body(), true)['id'];

        $response = $controller->destroy(new Request('DELETE', "/api/documents/{$documentId}", [], [], []), (string) $documentId);

        self::assertSame(204, $response->statusCode());
    }

    #[Test]

    public function testDestroyByNonGestionnaireIsRefused(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'kim@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('kim@rehearsalbox.test', 'password');
        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $storeResponse = $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());
        $documentId = json_decode($storeResponse->body(), true)['id'];

        $member = $this->createUser($userRepository, 'liam@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());
        $authService->attempt('liam@rehearsalbox.test', 'password');

        $this->expectException(AccessDeniedException::class);
        $controller->destroy(new Request('DELETE', "/api/documents/{$documentId}", [], [], []), (string) $documentId);
    }

    // --- Téléchargement isolé (#222) -------------------------------------------------------------------

    /** @return array{0: KernelTranslation, 1: int, 2: string} contrôleur, identifiant du document, chemin du fichier stocké */
    private function storedDocument(string $content, string $name, string $mime): array
    {
        [$controller, $groupRepository, $userRepository, $authService, $documentService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $manager = $this->createUser($userRepository, 'gaby@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $manager->id(), GroupUserRole::Gestionnaire);
        $authService->attempt('gaby@rehearsalbox.test', 'password');
        $files = ['document' => ['name' => $name, 'type' => $mime, 'tmp_name' => $this->uploadedTmpFile($content), 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)]];
        $stored = $controller->store(new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files), (string) $group->id());
        $id = json_decode($stored->body(), true)['id'];
        $path = $documentService->pathOf($documentService->resolveDownload($id, $manager->id()));

        return [$controller, $id, $path];
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    #[Test]
    public function testTheDownloadIsStreamedAndIsolatedByAStrictContentSecurityPolicy(): void
    {
        [$controller, $id, $path] = $this->storedDocument('%PDF-1.4 contenu test', 'fiche.pdf', 'application/pdf');

        $response = $controller->download(new Request('GET', "/api/documents/{$id}", [], [], []), (string) $id);

        self::assertInstanceOf(\App\Http\FileResponse::class, $response, 'envoyé en flux, jamais chargé en mémoire');
        self::assertSame($path, $response->path());
        self::assertSame("default-src 'none'; style-src 'unsafe-inline'; sandbox", $response->headers()['Content-Security-Policy']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertSame('application/pdf', $response->headers()['Content-Type']);
        self::assertSame((string) strlen('%PDF-1.4 contenu test'), $response->headers()['Content-Length']);
    }

    #[Test]
    public function testImagesStayInlineButPdfsAreAlwaysAttachments(): void
    {
        [$controller, $imageId] = $this->storedDocument((string) base64_decode(self::PNG), 'plan.png', 'image/png');

        $image = $controller->download(new Request('GET', "/api/documents/{$imageId}", [], [], []), (string) $imageId);

        self::assertSame('image/png', $image->headers()['Content-Type']);
        self::assertStringStartsWith('inline; filename="', $image->headers()['Content-Disposition']);
    }

    #[Test]
    public function testAMissingFileOnDiskIsAClean404NotAnEmpty200(): void
    {
        [$controller, $id, $path] = $this->storedDocument('%PDF-1.4 contenu test', 'fiche.pdf', 'application/pdf');
        unlink($path);

        $response = $controller->download(new Request('GET', "/api/documents/{$id}", [], [], []), (string) $id);

        self::assertSame(404, $response->statusCode());
        self::assertSame(['error' => 'Document introuvable.'], json_decode($response->body(), true));
    }

    #[Test]
    public function testAMalformedIdentifierIsRefusedAsForbidden(): void
    {
        [$controller] = $this->storedDocument('%PDF-1.4 contenu test', 'fiche.pdf', 'application/pdf');

        foreach (['5abc', '0', '-1', ''] as $id) {
            try {
                $controller->download(new Request('GET', '/x', [], [], []), $id);
                self::fail('identifiant refusé attendu : ' . $id);
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
