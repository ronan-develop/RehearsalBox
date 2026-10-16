<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Http;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Account\Repository\MysqlUserRepository;
use App\Account\Service\AuthServiceInterface;
use App\Container\Container;
use App\Http\Request;
use App\Kernel;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\SecurityHeaders;
use App\Security\SessionInterface;
use App\Tests\Database\TestDatabase;
use App\Tests\Doubles\InMemorySession;
use App\Tests\Doubles\RecordingMailer;
use Symfony\Component\Mailer\MailerInterface;

/** Noyau réel (routeur, conteneur, base de test) pour tester la comptabilité de bout en bout, par acteur : anon, musicien ou admin. */
trait HttpHarness
{
    /** @var array<string, array{0: Kernel, 1: Container}> */
    private array $actors = [];

    private function makeUser(string $name, UserRole $role): User
    {
        return (new MysqlUserRepository($this->pdo))->save(new User(
            id: 0,
            email: "{$name}@rehearsalbox.test",
            passwordHash: password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
            displayName: $name,
            role: $role,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));
    }

    /** @return array{0: Kernel, 1: Container} */
    private function actor(string $name): array
    {
        if (isset($this->actors[$name])) {
            return $this->actors[$name];
        }
        $config = require __DIR__ . '/../../../config/config.php';
        $config['db'] = [
            'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_TEST_PORT') ?: '3307',
            'name' => getenv('DB_TEST_NAME') ?: 'rehearsalbox_test',
            'user' => getenv('DB_TEST_USER') ?: 'root',
            'password' => getenv('DB_TEST_PASSWORD') ?: 'root',
        ];
        $config['messages']['key_file'] = \App\Tests\Support\TestMessageCipher::keyFile();
        $container = (require __DIR__ . '/../../../config/services.php')($config);
        $session = new InMemorySession();
        $container->set(SessionInterface::class, static fn () => $session);
        $container->set(MailerInterface::class, static fn () => new RecordingMailer());

        $routes = require __DIR__ . '/../../../config/routes.php';
        $router = new Router();
        foreach ([...$routes['pages'], ...$routes['api']] as [$method, $pattern, $handler]) {
            $router->add($method, $pattern, $handler);
        }
        if ($name !== 'anon') {
            $container->get(AuthServiceInterface::class)->attempt("{$name}@rehearsalbox.test", 'password');
        }

        return $this->actors[$name] = [new Kernel($router, $container, $container->get(CsrfTokenManager::class), new SecurityHeaders()), $container];
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @return array{0: int, 1: string, 2: array<string, string>} statut, corps, en-têtes
     */
    private function call(string $actor, string $method, string $path, array $body = [], array $query = [], bool $csrf = true): array
    {
        [$kernel, $container] = $this->actor($actor);
        $headers = $csrf ? ['X-CSRF-Token' => $container->get(CsrfTokenManager::class)->getToken()] : [];
        $response = $kernel->handle(new Request($method, $path, $query, $body, $headers, []));

        return [$response->statusCode(), $response->body(), $response->headers()];
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'réponse JSON attendue : ' . $body);

        return $decoded;
    }
}
