<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\AccountApiController;
use App\Database\TransactionRunner;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Http\Request;
use App\Group\Repository\MysqlGroupRepository;
use App\Repository\MysqlPasswordResetRepository;
use App\Repository\MysqlUserRepository;
use App\Security\AuthGuard;
use App\Security\Exception\UnauthenticatedException;
use App\Tests\Support\FastPasswordHasher;
use App\Security\PasswordPolicy;
use App\Service\AccountSecurityService;
use App\Service\EmailChangeService;
use App\Service\ProfileService;
use App\Repository\MysqlEmailChangeRepository;
use App\Service\AuthService;
use App\Service\PasswordChangeService;
use App\Service\PasswordResetService;
use App\Tests\RepositoryTestCase;
use App\Tests\Security\InMemorySession;
use App\Tests\Support\RecordingMailer;
use App\Tests\Support\KernelTranslation;
use PHPUnit\Framework\Attributes\Test;
use App\Tests\Support\TestMailbox;

#[\PHPUnit\Framework\Attributes\Group('db')]
final class AccountApiControllerTest extends RepositoryTestCase
{
    private MysqlUserRepository $users;
    private RecordingMailer $mailer;
    private AuthService $auth;
    private AccountSecurityService $security;
    /** Le contrôleur tel que le sert le Kernel : une exception métier devient sa réponse (KernelTranslation). */
    private KernelTranslation $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->mailer = new RecordingMailer();
        $this->auth = new AuthService($this->users, new FastPasswordHasher(), new InMemorySession(), new MysqlGroupRepository($this->pdo));
        $resets = new MysqlPasswordResetRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $hasher = new FastPasswordHasher();
        $policy = new PasswordPolicy();
        $resetService = new PasswordResetService($this->users, $resets, $hasher, $policy, TestMailbox::of($this->mailer), $transactions);
        $this->security = new AccountSecurityService($this->users, $resets, TestMailbox::of($this->mailer), $transactions, $resetService);
        $this->controller = new KernelTranslation(new AccountApiController(
            new AuthGuard($this->auth),
            $this->auth,
            new PasswordChangeService($this->users, $hasher, $policy, $this->security),
            $this->security,
            new ProfileService($this->users, new \App\Repository\MysqlNotificationPreferenceRepository($this->pdo)),
            new EmailChangeService($this->users, new MysqlEmailChangeRepository($this->pdo), $resets, $hasher, TestMailbox::of($this->mailer), $transactions),
        ));
    }

    private function insertUser(string $email = 'alice@rehearsalbox.test'): User
    {
        return $this->users->save(new User(0, $email, (new FastPasswordHasher())->hash('ancien-mdp'), 'Utilisateur', UserRole::Musicien, true, 0, null));
    }

    private function post(string $path, array $body): Request
    {
        return new Request('POST', $path, [], $body, []);
    }

    private function change(array $overrides = []): Request
    {
        return $this->post('/api/auth/change-password', $overrides + [
            'currentPassword' => 'ancien-mdp',
            'password' => 'nouveau-mdp',
            'passwordConfirmation' => 'nouveau-mdp',
        ]);
    }

    #[Test]
    public function testChangePasswordRequiresALoggedInUser(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->controller->changePassword($this->change());
    }

    #[Test]
    public function testChangePasswordUpdatesThePasswordAndKeepsTheCurrentSessionLoggedIn(): void
    {
        $user = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->changePassword($this->change());

        self::assertSame(200, $response->statusCode());
        self::assertTrue((new FastPasswordHasher())->verify('nouveau-mdp', $this->users->findById($user->id())->passwordHash()));
        self::assertNotNull($this->auth->currentUser());
    }

    #[Test]
    public function testChangePasswordAlwaysTargetsTheSessionUserNeverAnIdFromTheRequest(): void
    {
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $this->controller->changePassword($this->change(['userId' => $bob->id(), 'id' => $bob->id(), 'email' => 'bob@rehearsalbox.test']));

        self::assertTrue((new FastPasswordHasher())->verify('ancien-mdp', $this->users->findById($bob->id())->passwordHash()));
        self::assertTrue((new FastPasswordHasher())->verify('nouveau-mdp', $this->users->findById($alice->id())->passwordHash()));
    }

    #[Test]
    public function testChangePasswordWithAWrongCurrentPasswordReturns422(): void
    {
        $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->changePassword($this->change(['currentPassword' => 'faux']));

        self::assertSame(422, $response->statusCode());
        self::assertArrayHasKey('currentPassword', json_decode($response->body(), true)['fields']);
    }

    #[Test]
    public function testChangePasswordWithAWeakNewPasswordReturns422(): void
    {
        $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->changePassword($this->change(['password' => 'court', 'passwordConfirmation' => 'court']));

        self::assertSame(422, $response->statusCode());
        self::assertArrayHasKey('password', json_decode($response->body(), true)['fields']);
    }

    #[Test]
    public function testSecureAccountWithAValidAlertTokenReturns200AndLocksTheAccount(): void
    {
        $user = $this->insertUser();
        $this->security->sendPasswordChangedAlert($user);
        preg_match('/token=([0-9a-f]{64})/', (string) $this->mailer->sent[0]->getTextBody(), $matches);

        $response = $this->controller->secureAccount($this->post('/api/auth/secure-account', ['token' => $matches[1]]));

        self::assertSame(200, $response->statusCode());
        self::assertTrue($this->users->findById($user->id())->isLocked(new \DateTimeImmutable()));
    }

    #[Test]
    public function testSecureAccountWithAnInvalidTokenReturns422WithoutDetails(): void
    {
        $response = $this->controller->secureAccount($this->post('/api/auth/secure-account', ['token' => str_repeat('a', 64)]));

        self::assertSame(422, $response->statusCode());
        self::assertStringContainsString('invalide ou expiré', $response->body());
    }

    #[Test]
    public function testSecureAccountWithoutTokenReturns422(): void
    {
        self::assertSame(422, $this->controller->secureAccount($this->post('/api/auth/secure-account', []))->statusCode());
    }

    // --- Mon nom affiché (#161) ---------------------------------------------------------------

    private function patchProfile(array $body): Request
    {
        return new Request('PATCH', '/api/account/profile', [], $body, []);
    }

    #[Test]
    public function testUpdateProfileRenamesTheLoggedInUser(): void
    {
        $alice = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->updateProfile($this->patchProfile(['displayName' => '  Alice Martin ']));

        self::assertSame(200, $response->statusCode());
        self::assertSame(['displayName' => 'Alice Martin'], json_decode($response->body(), true));
        self::assertSame('Alice Martin', $this->users->findById($alice->id())->displayName());
    }

    #[Test]
    public function testUpdateProfileRequiresALoggedInUser(): void
    {
        $this->expectException(\App\Security\Exception\UnauthenticatedException::class);

        $this->controller->updateProfile($this->patchProfile(['displayName' => 'Intrus']));
    }

    #[Test]
    public function testUpdateProfileIgnoresAnyUserIdOrOtherFieldSentByTheClient(): void
    {
        $alice = $this->insertUser();
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $this->controller->updateProfile($this->patchProfile([
            'displayName' => 'Alice Martin',
            'userId' => $bob->id(), 'id' => $bob->id(),
            'email' => 'pirate@rehearsalbox.test', 'role' => 'admin', 'isActive' => false,
        ]));

        // Bob n'a pas bougé : seul le compte de la session est modifié.
        self::assertSame('Utilisateur', $this->users->findById($bob->id())->displayName());
        $updated = $this->users->findById($alice->id());
        self::assertSame('Alice Martin', $updated->displayName());
        self::assertSame('alice@rehearsalbox.test', $updated->email(), "l'e-mail ne se change pas par cette route");
        self::assertSame(UserRole::Musicien, $updated->role(), 'on ne se donne pas un rôle admin');
        self::assertTrue($updated->isActive());
    }

    #[Test]
    public function testUpdateProfileReturns422WithTheFieldForInvalidNames(): void
    {
        $alice = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        foreach ([[], ['displayName' => ''], ['displayName' => '   '], ['displayName' => str_repeat('a', 101)], ['displayName' => ['x']], ['displayName' => 12], ['displayName' => "Ali\nce"]] as $body) {
            $response = $this->controller->updateProfile($this->patchProfile($body));

            self::assertSame(422, $response->statusCode(), json_encode($body));
            self::assertArrayHasKey('displayName', json_decode($response->body(), true)['fields']);
        }
        self::assertSame('Utilisateur', $this->users->findById($alice->id())->displayName());
    }

    #[Test]
    public function testRenamingDoesNotCloseTheOtherSessions(): void
    {
        $alice = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');
        $versionBefore = $this->users->findById($alice->id())->sessionVersion();

        $this->controller->updateProfile($this->patchProfile(['displayName' => 'Alice Martin']));

        self::assertSame($versionBefore, $this->users->findById($alice->id())->sessionVersion());
        self::assertNotNull($this->auth->currentUser(), 'la session reste ouverte');
    }

    // --- Mon adresse e-mail (#164) --------------------------------------------------------------

    private function patchEmail(array $body): Request
    {
        return new Request('PATCH', '/api/account/email', [], $body, []);
    }

    private function confirmRequest(array $body): Request
    {
        return new Request('POST', '/api/account/email/confirm', [], $body, []);
    }

    private function requestedToken(): string
    {
        self::assertSame(1, preg_match('#/account/email/confirm\?token=([0-9a-f]{64})#', (string) $this->mailer->sent[0]->getTextBody(), $m));

        return $m[1];
    }

    #[Test]
    public function testRequestEmailChangeSendsTheLinkToTheNewAddressAndAnswersGenerically(): void
    {
        $alice = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $response = $this->controller->requestEmailChange($this->patchEmail(['email' => 'nouvelle@rehearsalbox.test', 'currentPassword' => 'ancien-mdp']));

        self::assertSame(200, $response->statusCode());
        self::assertSame('ok', json_decode($response->body(), true)['status']);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame(['nouvelle@rehearsalbox.test'], array_map(static fn ($a) => $a->getAddress(), $this->mailer->sent[0]->getTo()));
        self::assertStringNotContainsString($this->requestedToken(), $response->body(), 'le jeton ne sort jamais de l\'e-mail');
        self::assertSame('alice@rehearsalbox.test', $this->users->findById($alice->id())->email());
    }

    #[Test]
    public function testRequestEmailChangeRequiresALoggedInUser(): void
    {
        $this->expectException(\App\Security\Exception\UnauthenticatedException::class);

        $this->controller->requestEmailChange($this->patchEmail(['email' => 'x@rehearsalbox.test', 'currentPassword' => 'ancien-mdp']));
    }

    #[Test]
    public function testRequestEmailChangeActsOnTheSessionAccountNeverOnAnIdSentByTheClient(): void
    {
        $alice = $this->insertUser();
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $this->controller->requestEmailChange($this->patchEmail([
            'email' => 'nouvelle@rehearsalbox.test', 'currentPassword' => 'ancien-mdp', 'userId' => $bob->id(), 'id' => $bob->id(),
        ]));

        self::assertSame([$alice->id()], array_map('intval', $this->pdo->query('SELECT user_id FROM email_changes')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    #[Test]
    public function testRequestEmailChangeReturns422WithTheFieldForBadInput(): void
    {
        $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $wrongPassword = $this->controller->requestEmailChange($this->patchEmail(['email' => 'nouvelle@rehearsalbox.test', 'currentPassword' => 'faux']));
        self::assertSame(422, $wrongPassword->statusCode());
        self::assertArrayHasKey('currentPassword', json_decode($wrongPassword->body(), true)['fields']);

        foreach ([['email' => 'pas-un-email', 'currentPassword' => 'ancien-mdp'], ['email' => ['a@b.test'], 'currentPassword' => 'ancien-mdp'], ['currentPassword' => 'ancien-mdp']] as $body) {
            $response = $this->controller->requestEmailChange($this->patchEmail($body));
            self::assertSame(422, $response->statusCode(), json_encode($body));
            self::assertArrayHasKey('email', json_decode($response->body(), true)['fields']);
        }
        self::assertSame([], $this->mailer->sent);
    }

    #[Test]
    public function testRequestEmailChangeToAnExistingAddressAnswersExactlyLikeASuccess(): void
    {
        $this->insertUser();
        $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $taken = $this->controller->requestEmailChange($this->patchEmail(['email' => 'bob@rehearsalbox.test', 'currentPassword' => 'ancien-mdp']));
        $free = $this->controller->requestEmailChange($this->patchEmail(['email' => 'libre@rehearsalbox.test', 'currentPassword' => 'ancien-mdp']));

        self::assertSame([$free->statusCode(), $free->body()], [$taken->statusCode(), $taken->body()], 'aucune différence observable : pas d\'énumération des comptes');
    }

    #[Test]
    public function testConfirmEmailChangeIsPublicChangesTheAddressAndTheTokenWorksOnce(): void
    {
        $alice = $this->insertUser();
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');
        $this->controller->requestEmailChange($this->patchEmail(['email' => 'nouvelle@rehearsalbox.test', 'currentPassword' => 'ancien-mdp']));
        $token = $this->requestedToken();
        $this->auth->logout();

        $first = $this->controller->confirmEmailChange($this->confirmRequest(['token' => $token]));
        $second = $this->controller->confirmEmailChange($this->confirmRequest(['token' => $token]));

        self::assertSame(200, $first->statusCode());
        self::assertSame('nouvelle@rehearsalbox.test', $this->users->findById($alice->id())->email());
        self::assertSame(422, $second->statusCode());
        self::assertSame('Ce lien est invalide ou a expiré.', json_decode($second->body(), true)['error']);
    }

    #[Test]
    public function testConfirmEmailChangeRefusesMissingOrMalformedTokens(): void
    {
        foreach ([[], ['token' => ''], ['token' => ['x']], ['token' => 12], ['token' => str_repeat('z', 64)]] as $body) {
            $response = $this->controller->confirmEmailChange($this->confirmRequest($body));

            self::assertSame(422, $response->statusCode(), json_encode($body));
        }
    }

    // --- Mes e-mails de mention (#178) -----------------------------------------------------------------

    private function patchNotifications(mixed $value): \App\Http\JsonResponse
    {
        return $this->controller->updateNotifications(new Request('PATCH', '/api/account/notifications', [], ['emailNotifications' => $value], []));
    }

    #[Test]
    public function testNotificationsRequireALoggedInUser(): void
    {
        $this->expectException(UnauthenticatedException::class);

        $this->patchNotifications(false);
    }

    #[Test]
    public function testIUnsubscribeAndResubscribeAndOnlyMyOwnPreferenceChanges(): void
    {
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');
        $preferences = new \App\Repository\MysqlNotificationPreferenceRepository($this->pdo);

        foreach ([false, '0', 'false'] as $off) {
            $preferences->setEmailEnabled($alice->id(), true);
            self::assertSame(200, $this->patchNotifications($off)->statusCode());
            self::assertFalse($preferences->emailEnabled($alice->id()), json_encode($off));
        }
        self::assertTrue($preferences->emailEnabled($bob->id()), 'celle des autres ne bouge pas');

        foreach ([true, '1', 'true'] as $on) {
            $preferences->setEmailEnabled($alice->id(), false);
            self::assertSame(200, $this->patchNotifications($on)->statusCode());
            self::assertTrue($preferences->emailEnabled($alice->id()), json_encode($on));
        }
    }

    #[Test]
    public function testTheTargetIsAlwaysTheSessionUserNeverAnIdFromTheRequest(): void
    {
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $bob = $this->insertUser('bob@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        $this->controller->updateNotifications(new Request('PATCH', '/api/account/notifications', [], ['emailNotifications' => '0', 'userId' => $bob->id(), 'id' => $bob->id()], []));

        $preferences = new \App\Repository\MysqlNotificationPreferenceRepository($this->pdo);
        self::assertFalse($preferences->emailEnabled($alice->id()));
        self::assertTrue($preferences->emailEnabled($bob->id()));
    }

    #[Test]
    public function testAnUnclearValueAnswers422WithTheFieldAndChangesNothing(): void
    {
        $alice = $this->insertUser('alice@rehearsalbox.test');
        $this->auth->attempt('alice@rehearsalbox.test', 'ancien-mdp');

        foreach ([null, 'peut-être', ['x'], 2, ''] as $value) {
            $response = $this->patchNotifications($value);
            self::assertSame(422, $response->statusCode(), json_encode($value));
            self::assertArrayHasKey('emailNotifications', json_decode($response->body(), true)['fields']);
        }
        self::assertTrue((new \App\Repository\MysqlNotificationPreferenceRepository($this->pdo))->emailEnabled($alice->id()));
    }
}
