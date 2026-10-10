<?php

declare(strict_types=1);

namespace App\Tests\Messaging\Controller\Api;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\AuthService;
use App\Database\TransactionRunner;
use App\Group\Repository\MysqlGroupRepository;
use App\Http\Request;
use App\Messaging\Controller\Api\DirectConversationApiController;
use App\Messaging\Repository\MysqlConversationMessageRepository;
use App\Messaging\Repository\MysqlConversationPresenceRepository;
use App\Messaging\Repository\MysqlConversationRepository;
use App\Messaging\Service\Direct\DirectConversationService;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Scenarios\KernelTranslation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #269 : POST /api/conversations/direct : l'émetteur vient de la session, la cible est validée côté serveur. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class DirectConversationApiControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test';

    private MysqlUserRepository $users;
    private AuthService $auth;
    private KernelTranslation $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), new InMemorySession(), new MysqlGroupRepository($this->pdo));
        $service = new DirectConversationService(
            new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()),
            new MysqlConversationMessageRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()),
            new MysqlConversationPresenceRepository($this->pdo),
            $this->users,
            new TransactionRunner($this->pdo),
            new MockClock('2026-10-04 12:00:00'),
        );
        $this->controller = new KernelTranslation(new DirectConversationApiController($service, new AuthGuard($this->auth)));
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), $name, UserRole::Musicien, true, 0, null));
    }

    /** @param array<string, mixed> $body @return array{int, array<string, mixed>} */
    private function start(array $body): array
    {
        $response = $this->controller->start(new Request('POST', '/api/conversations/direct', [], $body, []));

        return [$response->statusCode(), json_decode($response->body(), true) ?? []];
    }

    #[Test]
    public function testStartingCreatesTheConversationAndAnswers201WithItsIdentifier(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $this->auth->attempt($alice->email(), self::PASSWORD);

        [$status, $json] = $this->start(['targetUserId' => $bob->id(), 'message' => 'Salut']);

        self::assertSame(201, $status);
        $found = (new MysqlConversationRepository($this->pdo, \App\Tests\Support\TestMessageCipher::make()))->findById($json['id']);
        self::assertTrue($found->isDirect());
        self::assertSame($alice->id(), $found->createdBy());
    }

    #[Test]
    public function testAnInvalidMessageAnswers422WithTheField(): void
    {
        $alice = $this->user('Alice');
        $bob = $this->user('Bob');
        $this->auth->attempt($alice->email(), self::PASSWORD);

        [$status, $json] = $this->start(['targetUserId' => $bob->id(), 'message' => '']);

        self::assertSame(422, $status);
        self::assertArrayHasKey('message', $json['fields']);
    }

    #[Test]
    public function testAMalformedYourselfOrUnknownTargetIsRefusedAsForbiddenAccess(): void
    {
        $alice = $this->user('Alice');
        $this->auth->attempt($alice->email(), self::PASSWORD);

        foreach ([null, 'abc', '-1', [1], $alice->id(), 9999] as $target) {
            try {
                $this->start(['targetUserId' => $target, 'message' => 'Salut']);
                self::fail('Refus attendu');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testAnAnonymousVisitorIsRefused(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->start(['targetUserId' => 1, 'message' => 'Salut']);
    }
}
