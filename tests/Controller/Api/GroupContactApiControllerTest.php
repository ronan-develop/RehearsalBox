<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\GroupContactApiController;
use App\Entity\Enum\UserRole;
use App\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\UnauthenticatedException;
use App\Security\NativePasswordHasher;
use App\Service\AuthService;
use App\Service\GroupContactService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class GroupContactApiControllerTest extends RepositoryTestCase
{
    private function makeController(): array
    {
        $groupRepository = new MysqlGroupRepository($this->pdo);
        $userRepository = new MysqlUserRepository($this->pdo);

        $session = new InMemorySession();
        $authService = new AuthService($userRepository, new NativePasswordHasher(), $session);
        $authGuard = new AuthGuard($authService);

        $mailer = new class implements MailerInterface {
            public ?Email $sent = null;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->sent = $message;
            }
        };
        $contactService = new GroupContactService($mailer, $groupRepository);

        $controller = new GroupContactApiController($contactService, $authGuard);

        return [$controller, $groupRepository, $userRepository, $authService, $mailer];
    }

    private function createLoggedInUser(MysqlUserRepository $userRepository, AuthService $authService): User
    {
        $user = $userRepository->save(new User(
            id: 0,
            email: 'alice@rehearsalbox.test',
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: 'Alice',
            role: UserRole::Musicien,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
        $authService->attempt('alice@rehearsalbox.test', 'password');

        return $user;
    }

    public function testSendByLoggedInUserReturns200AndSendsEmail(): void
    {
        [$controller, $groupRepository, $userRepository, $authService, $mailer] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));

        $request = new Request('POST', "/api/groups/{$group->id()}/contact", [], ['message' => 'Bonjour, un échange possible ?'], []);
        $response = $controller->send($request, (string) $group->id());

        self::assertSame(200, $response->statusCode());
        self::assertNotNull($mailer->sent);
    }

    public function testSendWithoutSessionThrowsUnauthenticated(): void
    {
        [$controller, $groupRepository] = $this->makeController();
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));

        $this->expectException(UnauthenticatedException::class);

        $request = new Request('POST', "/api/groups/{$group->id()}/contact", [], ['message' => 'Bonjour'], []);
        $controller->send($request, (string) $group->id());
    }

    public function testSendWithUnknownGroupReturns404(): void
    {
        [$controller, , $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);

        $request = new Request('POST', '/api/groups/9999/contact', [], ['message' => 'Bonjour'], []);
        $response = $controller->send($request, '9999');

        self::assertSame(404, $response->statusCode());
    }

    public function testSendWithEmptyMessageReturns422(): void
    {
        [$controller, $groupRepository, $userRepository, $authService] = $this->makeController();
        $this->createLoggedInUser($userRepository, $authService);
        $group = $groupRepository->save(new Group(0, 'Groupe Titulaire', null, null, 'contact@example.test'));

        $request = new Request('POST', "/api/groups/{$group->id()}/contact", [], ['message' => ''], []);
        $response = $controller->send($request, (string) $group->id());

        self::assertSame(422, $response->statusCode());
    }
}
