<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\MemberApiController;
use App\Entity\Enum\UserRole;
use App\Group\Entity\Group;
use App\Entity\User;
use App\Http\Request;
use App\Repository\MysqlConversationGuestRepository;
use App\Repository\MysqlConversationRepository;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlMemberDirectory;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\FastPasswordHasher;
use App\Service\AuthService;
use App\Service\ConversationAccess;
use App\Service\MemberSearchService;
use App\Tests\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class MemberApiControllerTest extends RepositoryTestCase
{
    private const PASSWORD = 'mot-de-passe-de-test';

    private MemberApiController $controller;
    private MysqlGroupRepository $groups;
    private MysqlUserRepository $users;
    private AuthService $auth;
    private int $conversationId;
    private User $alice;
    private User $erin;
    private int $alpha;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->groups = new MysqlGroupRepository($this->pdo);
        $this->users = new MysqlUserRepository($this->pdo);
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), new InMemorySession(), $this->groups);
        $conversations = new MysqlConversationRepository($this->pdo);
        $guests = new MysqlConversationGuestRepository($this->pdo);
        $this->controller = new MemberApiController(
            new MemberSearchService(new MysqlMemberDirectory($this->pdo), new ConversationAccess($conversations, $this->groups, $guests), $this->groups, $guests),
            new AuthGuard($this->auth),
        );
        $this->alice = $this->user('Alice');
        $this->erin = $this->user('Erin');
        $denis = $this->user('Denis');
        $this->alpha = $this->group('Alpha', $this->alice)->id();
        $this->beta = $this->group('Beta', $this->user('Bob'))->id();
        $this->group('Carnage', $denis);
        $this->conversationId = $conversations->create($this->alpha, $this->beta, null, new \DateTimeImmutable('2026-10-06 12:00:00'), $this->alice->id())->id();
    }

    private function user(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), $name, UserRole::Musicien, true, 0, null));
    }

    private function group(string $name, User ...$members): Group
    {
        $group = $this->groups->save(new Group(0, $name, null, null, strtolower($name) . '@rehearsalbox.test'));
        foreach ($members as $member) {
            $this->groups->addMember($group->id(), $member->id());
        }

        return $group;
    }

    /** @param array<string, mixed> $query @return array{int, array<string, mixed>} */
    private function search(array $query): array
    {
        $response = $this->controller->search(new Request('GET', '/api/members', $query, [], []));

        return [$response->statusCode(), json_decode($response->body(), true)];
    }

    #[Test]
    public function testAParticipantFindsMembersByNameWithTheirGroupAndNoEmail(): void
    {
        $this->auth->attempt($this->alice->email(), self::PASSWORD);

        [$status, $json] = $this->search(['q' => 'deni', 'conversation' => (string) $this->conversationId]);

        self::assertSame(200, $status);
        self::assertSame([['id' => $this->users->findByEmail('denis@rehearsalbox.test')->id(), 'name' => 'Denis', 'groups' => 'Carnage', 'participant' => false]], $json['members']);
        self::assertStringNotContainsString('@rehearsalbox.test', json_encode($json));
    }

    #[Test]
    public function testTheNewConversationPageSearchesWithItsTwoGroups(): void
    {
        $this->auth->attempt($this->alice->email(), self::PASSWORD);

        [, $json] = $this->search(['q' => 'bo', 'groupId' => (string) $this->alpha, 'targetGroupId' => (string) $this->beta]);

        self::assertSame(['Bob'], array_column($json['members'], 'name'));
    }

    #[Test]
    public function testShortOrMissingQueriesGiveAnEmptyList(): void
    {
        $this->auth->attempt($this->alice->email(), self::PASSWORD);

        self::assertSame([], $this->search(['q' => 'd', 'conversation' => (string) $this->conversationId])[1]['members']);
        self::assertSame([], $this->search(['conversation' => (string) $this->conversationId])[1]['members']);
        self::assertSame([], $this->search(['q' => ['x'], 'conversation' => (string) $this->conversationId])[1]['members']);
    }

    #[Test]
    public function testAnonymousAndOutsidersAreRefusedUniformly(): void
    {
        try {
            $this->search(['q' => 'denis', 'conversation' => (string) $this->conversationId]);
            self::fail('connexion exigée');
        } catch (UnauthenticatedException) {
            $this->addToAssertionCount(1);
        }

        $this->auth->attempt($this->erin->email(), self::PASSWORD);
        $messages = [];
        foreach ([
            ['q' => 'denis', 'conversation' => (string) $this->conversationId],
            ['q' => 'denis', 'conversation' => '999999'],
            ['q' => 'denis', 'conversation' => 'abc'],
            ['q' => 'denis'],
            ['q' => 'denis', 'groupId' => (string) $this->alpha, 'targetGroupId' => (string) $this->beta],
        ] as $query) {
            try {
                $this->search($query);
                self::fail('refus attendu : ' . json_encode($query));
            } catch (AccessDeniedException $e) {
                $messages[] = $e->getMessage();
            }
        }
        self::assertCount(1, array_unique($messages));
    }
}
