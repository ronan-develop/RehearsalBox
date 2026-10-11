<?php

declare(strict_types=1);

namespace App\Tests\Account\Controller\Api;

use App\Account\Controller\Api\AccountDataApiController;
use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlAccountDataRepository;
use App\Account\Repository\MysqlEmailChangeRepository;
use App\Account\Repository\MysqlPasswordResetRepository;
use App\Account\Service\Data\AccountDataExporter;
use App\Account\Service\Data\AccountDeletionService;
use App\Account\Service\AuthService;
use App\Account\Service\CurrentPasswordVerifier;
use App\Database\TransactionRunner;
use App\Group\Repository\MysqlGroupManagerRepository;
use App\Group\Service\GroupManagerService;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Doubles\RecordingMailer;
use App\Tests\Scenarios\KernelTranslation;
use App\Tests\Scenarios\MessagingScenario;
use App\Tests\Scenarios\TestMailbox;
use App\Tests\Support\TestMessageCipher;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/** #431 : export et suppression du compte de la SESSION : aucun identifiant n'est lu dans la requête. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountDataApiControllerTest extends RepositoryTestCase
{
    use MessagingScenario;

    private const PASSWORD = 'le-bon-mot-de-passe';

    private KernelTranslation $controller;
    private AuthService $auth;
    private InMemorySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $hasher = new FastPasswordHasher();
        $this->session = new InMemorySession();
        $this->auth = new AuthService($this->users, $hasher, $this->session, $this->groups);
        $clock = new MockClock('2026-10-20 10:00:00');
        $this->controller = new KernelTranslation(new AccountDataApiController(
            new AuthGuard($this->auth),
            $this->auth,
            new AccountDataExporter($this->users, new MysqlAccountDataRepository($this->pdo, TestMessageCipher::make()), $clock),
            new AccountDeletionService(
                $this->users,
                $this->groups,
                new GroupManagerService($this->groups, new MysqlGroupManagerRepository($this->pdo)),
                new CurrentPasswordVerifier($this->users, $hasher),
                new MysqlPasswordResetRepository($this->pdo),
                new MysqlEmailChangeRepository($this->pdo),
                $hasher,
                new TransactionRunner($this->pdo),
                TestMailbox::of(new RecordingMailer()),
                $clock,
            ),
        ));
    }

    private function signedIn(string $name): User
    {
        $user = $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), $name, UserRole::Musicien, true, 0, null));
        $this->auth->attempt($user->email(), self::PASSWORD);

        return $user;
    }

    private function delete(array $body): \App\Http\Response
    {
        return $this->controller->delete(new Request('DELETE', '/api/account', [], $body, []));
    }

    #[Test]
    public function testTheExportIsADownloadOfTheSignedInPersonOnly(): void
    {
        $this->user('Bob');
        $alice = $this->signedIn('Alice');
        $this->group('Alpha', $alice);

        $response = $this->controller->export(new Request('GET', '/api/account/export', [], [], []));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('attachment', $response->headers()['Content-Disposition']);
        $json = json_decode($response->body(), true);
        self::assertSame('alice@rehearsalbox.test', $json['account']['email']);
        self::assertStringNotContainsString('bob@', $response->body(), 'aucune donnée d\'une autre personne');
    }

    #[Test]
    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $this->expectException(UnauthenticatedException::class);
        $this->controller->export(new Request('GET', '/api/account/export', [], [], []));
    }

    #[Test]
    public function testTheAccountIsTheSessionOneWhateverTheRequestSays(): void
    {
        $bob = $this->user('Bob');
        $this->signedIn('Alice');

        $response = $this->controller->export(new Request('GET', '/api/account/export', ['userId' => (string) $bob->id(), 'id' => (string) $bob->id()], ['userId' => $bob->id()], []));

        self::assertSame('alice@rehearsalbox.test', json_decode($response->body(), true)['account']['email']);
        $this->delete(['userId' => $bob->id(), 'password' => self::PASSWORD, 'confirmation' => 'SUPPRIMER']);
        self::assertTrue($this->users->findById($bob->id())->isActive(), 'l\'identifiant de la requête est ignoré');
    }

    #[Test]
    public function testDeletionNeedsThePasswordAndTheConfirmationWord(): void
    {
        $alice = $this->signedIn('Alice');
        foreach ([['confirmation' => 'SUPPRIMER'], ['password' => self::PASSWORD], ['password' => self::PASSWORD, 'confirmation' => 'supprimer'], ['password' => 12, 'confirmation' => 'SUPPRIMER']] as $body) {
            self::assertSame(422, $this->delete($body)->statusCode());
        }
        self::assertSame(422, $this->delete(['password' => 'faux', 'confirmation' => 'SUPPRIMER'])->statusCode());

        self::assertTrue($this->users->findById($alice->id())->isActive());
    }

    #[Test]
    public function testDeletionErasesTheAccountAndEndsTheSession(): void
    {
        $alice = $this->signedIn('Alice');

        $response = $this->delete(['password' => self::PASSWORD, 'confirmation' => 'SUPPRIMER']);

        self::assertSame(204, $response->statusCode());
        self::assertSame('Ancien membre', $this->users->findById($alice->id())->displayName());
        self::assertNull($this->auth->currentUser(), 'la session est fermée');
        self::assertTrue($this->session->destroyed);
    }

    #[Test]
    public function testTheRulesOfAdministrationAreAnswered422WithAReadableMessage(): void
    {
        $admin = $this->users->save(new User(0, 'chef@rehearsalbox.test', (new FastPasswordHasher())->hash(self::PASSWORD), 'Chef', UserRole::Admin, true, 0, null));
        $this->auth->attempt($admin->email(), self::PASSWORD);

        $response = $this->delete(['password' => self::PASSWORD, 'confirmation' => 'SUPPRIMER']);

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('administrateur', json_decode($response->body(), true)['error']);
        self::assertTrue($this->users->findById($admin->id())->isActive());
    }
}
