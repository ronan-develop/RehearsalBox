<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\GroupDocumentApiController;
use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\GroupDocumentService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;

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
        $documentService = new GroupDocumentService($documentRepository, $groupRepository, $this->storagePath, 20);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session, $groupRepository);
        $authGuard = new AuthGuard($authService);

        $controller = new GroupDocumentApiController($documentService, $authGuard);

        return [$controller, $groupRepository, $userRepository, $authService];
    }

    private function createUser(MysqlUserRepository $userRepository, string $email): User
    {
        return $userRepository->save(new User(
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

    private function uploadedTmpFile(string $content): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'upload-test-');
        file_put_contents($tmpPath, $content);

        return $tmpPath;
    }

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

    public function testStoreByNonGestionnaireReturns403(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $member = $this->createUser($userRepository, 'bob@rehearsalbox.test');
        $groupRepository->addMember($group->id(), $member->id());
        $authService->attempt('bob@rehearsalbox.test', 'password');

        $tmpPath = $this->uploadedTmpFile('%PDF-1.4 contenu test');
        $files = ['document' => ['name' => 'fiche.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmpPath, 'error' => UPLOAD_ERR_OK, 'size' => 20]];
        $request = new Request('POST', "/api/groups/{$group->id()}/documents", [], [], [], $files);

        $response = $controller->store($request, (string) $group->id());

        self::assertSame(403, $response->statusCode());
    }

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

    public function testIndexByNonMemberReturns403(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Test', null, null, 'contact@example.test'));
        $this->createUser($userRepository, 'fanny@rehearsalbox.test');
        $authService->attempt('fanny@rehearsalbox.test', 'password');

        $response = $controller->index(new Request('GET', "/api/groups/{$group->id()}/documents", [], [], []), (string) $group->id());

        self::assertSame(403, $response->statusCode());
    }

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

    public function testDownloadByNonMemberReturns403(): void
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

        $response = $controller->download(new Request('GET', "/api/documents/{$documentId}", [], [], []), (string) $documentId);

        self::assertSame(403, $response->statusCode());
    }

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

    public function testDestroyByNonGestionnaireReturns403(): void
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

        $response = $controller->destroy(new Request('DELETE', "/api/documents/{$documentId}", [], [], []), (string) $documentId);

        self::assertSame(403, $response->statusCode());
    }
}
