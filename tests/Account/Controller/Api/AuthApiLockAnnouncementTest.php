<?php

declare(strict_types=1);

namespace App\Tests\Account\Controller\Api;

use App\Account\Controller\Api\AuthApiController;
use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\AuthService;
use App\Account\Service\Throttle\LoginThrottle;
use App\Group\Repository\MysqlGroupRepository;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Doubles\CountingPasswordHasher;
use App\Tests\Doubles\FastPasswordHasher;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Support\TestLoginThrottle;
use PHPUnit\Framework\Attributes\Test;

/**
 * #236 : après trop d'échecs, l'écran de connexion annonce « Trop de tentatives. Réessayez dans N minutes. » avec la durée restante.
 * Le blocage est tenu PAR IDENTIFIANT SAISI (réel ou inventé) : la réponse est identique, donc rien ne révèle quel compte existe.
 */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AuthApiLockAnnouncementTest extends RepositoryTestCase
{
    private AuthApiController $controller;
    private LoginThrottle $throttle;
    private MysqlUserRepository $users;
    private CountingPasswordHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new MysqlUserRepository($this->pdo);
        $this->hasher = new CountingPasswordHasher();
        $this->throttle = TestLoginThrottle::make($this->pdo);
        $this->controller = new AuthApiController(new AuthService($this->users, $this->hasher, new InMemorySession(), new MysqlGroupRepository($this->pdo)), $this->throttle);
        $this->users->save(new User(0, 'alice@rehearsalbox.test', (new FastPasswordHasher())->hash('bon-mot-de-passe'), 'Alice', UserRole::Musicien, true, 0, null));
    }

    private function login(string $email, string $password, string $ip = '203.0.113.7'): JsonResponse
    {
        return $this->controller->login(new Request('POST', '/api/auth/login', [], ['email' => $email, 'password' => $password], [], [], $ip));
    }

    /** @return array<string, mixed> */
    private function body(JsonResponse $response): array
    {
        return json_decode($response->body(), true);
    }

    /** Cinq échecs d'un identifiant, depuis des adresses différentes (la limite par adresse n'est pas en cause). */
    private function fiveFailures(string $email): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            self::assertSame(401, $this->login($email, 'faux', "203.0.113.{$i}")->statusCode(), "échec {$i} : encore « identifiants invalides »");
        }
    }

    /** Pose cinq échecs vieux de $secondsAgo secondes (le contrôleur lit l'heure réelle). */
    private function pastFailures(string $email, int $secondsAgo): void
    {
        $at = (new \DateTimeImmutable())->modify("-{$secondsAgo} seconds");
        for ($i = 1; $i <= 5; ++$i) {
            $this->throttle->recordFailure("198.51.100.{$i}", $email, $at);
        }
    }

    #[Test]
    public function testAfterFiveWrongPasswordsEvenTheRightOneIsRefusedWithTheRemainingTime(): void
    {
        $this->fiveFailures('alice@rehearsalbox.test');

        $response = $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.99');
        $body = $this->body($response);

        self::assertSame(429, $response->statusCode());
        self::assertSame('Trop de tentatives. Réessayez dans 15 minutes.', $body['error']);
        self::assertEqualsWithDelta(900, $body['retryAfterSeconds'], 2);
        self::assertSame((string) $body['retryAfterSeconds'], $response->headers()['Retry-After']);
    }

    #[Test]
    public function testAKnownAccountAndAnInventedAddressGetTheSameStatusMessageAndDuration(): void
    {
        $this->fiveFailures('alice@rehearsalbox.test');
        $this->fiveFailures('personne-ne-porte-ce-nom@rehearsalbox.test');

        $known = $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.98');
        $invented = $this->login('personne-ne-porte-ce-nom@rehearsalbox.test', 'nimporte', '198.51.100.98');

        self::assertSame($known->statusCode(), $invented->statusCode());
        self::assertSame($this->body($known)['error'], $this->body($invented)['error']);
        self::assertEqualsWithDelta($this->body($known)['retryAfterSeconds'], $this->body($invented)['retryAfterSeconds'], 2);
    }

    #[Test]
    public function testTheBlockedPathSpendsNoHashingForAnyoneAndTheOpenPathSpendsTheSameForEveryone(): void
    {
        $this->login('alice@rehearsalbox.test', 'faux');
        $knownCost = $this->hasher->verifications + $this->hasher->simulations;
        $this->login('inconnue@rehearsalbox.test', 'faux');
        self::assertSame($knownCost * 2, $this->hasher->verifications + $this->hasher->simulations, 'une opération de hachage dans tous les cas');

        $this->pdo->exec('DELETE FROM throttle_events');
        $this->fiveFailures('alice@rehearsalbox.test');
        $this->fiveFailures('inconnue@rehearsalbox.test');
        $before = $this->hasher->verifications + $this->hasher->simulations;
        $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.97');
        $this->login('inconnue@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.97');

        self::assertSame($before, $this->hasher->verifications + $this->hasher->simulations, 'bloqué : aucun hachage, ni pour le compte réel ni pour l\'inventé');
    }

    #[Test]
    public function testTheDurationShrinksAndIsRoundedUpToTheMinute(): void
    {
        $this->pastFailures('alice@rehearsalbox.test', 10 * 60); // bloqué depuis 10 minutes : il en reste 5
        self::assertSame('Trop de tentatives. Réessayez dans 5 minutes.', $this->body($this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.90'))['error']);

        $this->pdo->exec('DELETE FROM throttle_events');
        $this->pastFailures('alice@rehearsalbox.test', 14 * 60 + 30); // il reste 30 secondes : « 1 minute », jamais « 0 »
        $response = $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.91');
        self::assertSame('Trop de tentatives. Réessayez dans 1 minute.', $this->body($response)['error']);
        self::assertEqualsWithDelta(30, $this->body($response)['retryAfterSeconds'], 2);
    }

    #[Test]
    public function testOnceTheBlockIsOverTheMessageGoesAwayAndSigningInWorksAgain(): void
    {
        $this->pastFailures('alice@rehearsalbox.test', 15 * 60 + 5);

        $response = $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.92');

        self::assertSame(200, $response->statusCode());
        self::assertArrayNotHasKey('error', $this->body($response));
    }

    #[Test]
    public function testASuccessfulLoginStartsTheCountOverForThatIdentifier(): void
    {
        for ($i = 1; $i <= 4; ++$i) {
            $this->login('alice@rehearsalbox.test', 'faux', "203.0.113.{$i}");
        }
        self::assertSame(200, $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.93')->statusCode());

        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame(401, $this->login('alice@rehearsalbox.test', 'faux', "203.0.113.{$i}0")->statusCode());
        }
        self::assertSame(200, $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.94')->statusCode(), 'quatre échecs depuis la réussite : pas bloqué');
    }

    #[Test]
    public function testTheAddressLimitAnnouncesTheSameMessageWithItsOwnDuration(): void
    {
        $at = (new \DateTimeImmutable())->modify('-5 minutes');
        for ($i = 0; $i < 20; ++$i) {
            $this->throttle->recordFailure('203.0.113.7', "personne{$i}@rehearsalbox.test", $at);
        }

        $response = $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe');
        $body = $this->body($response);

        self::assertSame(429, $response->statusCode());
        self::assertSame('Trop de tentatives. Réessayez dans 10 minutes.', $body['error']);
        self::assertEqualsWithDelta(600, $body['retryAfterSeconds'], 2);
        self::assertSame((string) $body['retryAfterSeconds'], $response->headers()['Retry-After']);
    }

    #[Test]
    public function testTheIdentifierIsMatchedWhateverTheCaseOrSpaces(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->login($i % 2 === 0 ? ' ALICE@rehearsalbox.test ' : 'Alice@Rehearsalbox.test', 'faux', "203.0.113.{$i}");
        }

        self::assertSame(429, $this->login('alice@rehearsalbox.test', 'bon-mot-de-passe', '198.51.100.95')->statusCode());
    }
}
