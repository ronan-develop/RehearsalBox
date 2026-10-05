<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Container\Container;
use App\Entity\Enum\GroupUserRole;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\Weekday;
use App\Entity\Group;
use App\Entity\GroupDocument;
use App\Entity\User;
use App\Http\Request;
use App\Kernel;
use App\Migration\Migrator;
use App\Repository\MysqlConversationRepository;
use App\Repository\MysqlConversationMessageRepository;
use App\Repository\MysqlGroupDocumentRepository;
use App\Repository\MysqlGroupRepository;
use App\Repository\MysqlRecurringSlotRepository;
use App\Repository\MysqlSlotExceptionRepository;
use App\Repository\MysqlUserRepository;
use App\Routing\Router;
use App\Security\CsrfTokenManager;
use App\Security\SessionInterface;
use App\Service\Contract\AuthServiceInterface;
use App\Tests\Support\RecordingMailer;
use App\Tests\TestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Audit IDOR (#118) : chaque route qui prend un identifiant est jouée de bout en
 * bout (Kernel + conteneur réels + base de test) par des acteurs qui n'ont PAS le
 * droit d'agir sur la ressource d'un autre groupe.
 *
 * Politique de réponse : un objet interdit et un objet inexistant sont
 * indiscernables (même code, même corps) pour ne rien révéler sur l'existence
 * d'un identifiant — 403 sur les ressources de groupe, document et demande ; 401
 * pour l'anonyme. Les routes admin gardent leurs codes propres.
 *
 * Ce fichier ne teste que des refus : aucune donnée n'est modifiée, la base est
 * donc amorcée une seule fois pour toute la classe.
 *
 * Acteurs : anon, stranger (connecté sans groupe), outsiderB (membre de B,
 * demandeur), memberA (membre de A, titulaire), managerA (gestionnaire de A),
 * dual (membre de A et de B), admin.
 */
final class IdorMatrixTest extends TestCase
{
    private const MISSING_ID = '99999999';

    /** @var array<string, int|string> */
    private static array $ids = [];

    /** @var array<string, array{0: Kernel, 1: Container, 2: SessionInterface}> */
    private static array $actors = [];

    private static string $storagePath;

    public static function setUpBeforeClass(): void
    {
        $pdo = TestDatabase::connection();
        TestDatabase::reset($pdo);
        (new Migrator($pdo, __DIR__ . '/../../database/migrations'))->run();

        $users = new MysqlUserRepository($pdo);
        $groups = new MysqlGroupRepository($pdo);
        $slots = new MysqlRecurringSlotRepository($pdo);
        $exceptions = new MysqlSlotExceptionRepository($pdo);
        $documents = new MysqlGroupDocumentRepository($pdo);

        $makeUser = static fn (string $name, UserRole $role): User => $users->save(new User(
            id: 0,
            email: "{$name}@rehearsalbox.test",
            passwordHash: password_hash('password', PASSWORD_DEFAULT),
            displayName: $name,
            role: $role,
            isActive: true,
            failedLoginAttempts: 0,
            lockedUntil: null,
        ));

        $stranger = $makeUser('stranger', UserRole::Musicien);
        $outsiderB = $makeUser('outsiderB', UserRole::Musicien);
        $memberA = $makeUser('memberA', UserRole::Musicien);
        $managerA = $makeUser('managerA', UserRole::Musicien);
        $dual = $makeUser('dual', UserRole::Musicien);
        $makeUser('admin', UserRole::Admin);

        $groupA = $groups->save(new Group(0, 'Groupe A', null, null, 'a@example.test'));
        $groupB = $groups->save(new Group(0, 'Groupe B', null, null, 'b@example.test'));
        $groups->addMember($groupA->id(), $memberA->id());
        $groups->addMember($groupA->id(), $managerA->id(), GroupUserRole::Gestionnaire);
        $groups->addMember($groupA->id(), $dual->id());
        $groups->addMember($groupB->id(), $outsiderB->id());
        $groups->addMember($groupB->id(), $dual->id());

        $slotA = $slots->save(new \App\Entity\RecurringSlot(0, $groupA->id(), Weekday::Tuesday, '18:00:00', '20:00:00', true));

        // Demande du groupe B (demandeur) sur le créneau de A (titulaire).
        $exceptionBA = $exceptions->createRequest($slotA->id(), new \DateTimeImmutable('+7 days'), $groupB->id(), $outsiderB->id(), 'Concert');

        $document = $documents->save(new GroupDocument(0, $groupA->id(), 'secret-de-A.pdf', 'stored-secret.pdf', 'application/pdf', 10, $managerA->id()));

        // Conversation entre A (initiateur) et B (visé) : seuls les membres de A ou B y ont accès.
        $conversations = new MysqlConversationRepository($pdo);
        $messages = new MysqlConversationMessageRepository($pdo);
        $conversationAB = $conversations->create($groupA->id(), $groupB->id(), 'Secret entre A et B', new \DateTimeImmutable());
        $messages->addMessage($conversationAB->id(), $memberA->id(), 'Message confidentiel', new \DateTimeImmutable());

        self::$storagePath = sys_get_temp_dir() . '/rb-idor-' . bin2hex(random_bytes(4));
        mkdir(self::$storagePath);

        self::$ids = [
            'groupA' => $groupA->id(),
            'slugA' => \App\Support\Slug::from($groupA->name()),
            'slotA' => $slotA->id(),
            'excBA' => $exceptionBA->id(),
            'docA' => $document->id(),
            'userMemberA' => $memberA->id(),
            'convAB' => $conversationAB->id(),
            'groupB' => $groupB->id(),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        array_map('unlink', glob(self::$storagePath . '/*') ?: []);
        @rmdir(self::$storagePath);
        self::$actors = [];
    }

    // --- Routes et acteurs refusés -------------------------------------------

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}> */
    public static function deniedAccessProvider(): iterable
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');

        $routes = [
            // Disponibilités
            ['GET', '/api/availability/pending/{groupA}', [], ['anon', 'stranger', 'outsiderB', 'admin']],
            ['GET', '/api/availability/requested/{groupA}', [], ['anon', 'stranger', 'outsiderB', 'admin']],
            ['POST', '/api/availability/{excBA}/respond', ['accepted' => true], ['anon', 'stranger', 'outsiderB', 'admin', 'dual']],
            ['PATCH', '/api/availability/{excBA}', ['occurrenceDate' => $future], ['anon', 'stranger', 'memberA', 'managerA', 'admin']],
            ['DELETE', '/api/availability/{excBA}', [], ['anon', 'stranger', 'memberA', 'managerA', 'admin']],
            // Admin : créneaux et groupes
            ['PATCH', '/api/admin/slots/{slotA}', ['startTime' => '19:00:00', 'endTime' => '21:00:00'], ['anon', 'stranger', 'memberA', 'managerA']],
            ['DELETE', '/api/admin/slots/{slotA}', [], ['anon', 'stranger', 'memberA', 'managerA']],
            ['PATCH', '/api/admin/groups/{groupA}', ['name' => 'Piraté', 'contactEmail' => 'x@example.test'], ['anon', 'stranger', 'memberA', 'managerA']],
            ['DELETE', '/api/admin/groups/{groupA}', [], ['anon', 'stranger', 'memberA', 'managerA']],
            ['POST', '/api/admin/groups/{groupA}/members', ['email' => 'stranger@rehearsalbox.test'], ['anon', 'stranger', 'memberA', 'managerA']],
            ['DELETE', '/api/admin/groups/{groupA}/members/{userMemberA}', [], ['anon', 'stranger', 'memberA', 'managerA']],
            // Admin : utilisateurs (#138) — comptes, désactivation, déblocage
            ['GET', '/api/admin/users', [], ['anon', 'stranger', 'memberA', 'managerA']],
            ['POST', '/api/admin/users', ['email' => 'intrus@rehearsalbox.test', 'displayName' => 'Intrus', 'role' => 'admin'], ['anon', 'stranger', 'memberA', 'managerA']],
            ['PATCH', '/api/admin/users/{userMemberA}', ['active' => false], ['anon', 'stranger', 'memberA', 'managerA']],
            ['POST', '/api/admin/users/{userMemberA}/unlock', [], ['anon', 'stranger', 'memberA', 'managerA']],
            // Mon compte (#161) : seul l'utilisateur connecté, sur son propre compte
            ['PATCH', '/api/account/profile', ['displayName' => 'Intrus'], ['anon']],
            ['PATCH', '/api/account/notifications', ['emailNotifications' => '0'], ['anon']],
            ['PATCH', '/api/account/email', ['email' => 'intrus@rehearsalbox.test', 'currentPassword' => 'x'], ['anon']],
            // Espace groupe
            ['GET', '/api/groups/{groupA}/space', [], ['anon', 'stranger', 'outsiderB']],
            ['PATCH', '/api/groups/{groupA}/space', ['lineup' => [], 'upcomingShows' => []], ['anon', 'stranger', 'outsiderB', 'memberA']],
            // Messagerie (#153) : réservée aux membres des deux groupes de la conversation
            ['GET', '/api/conversations', [], ['anon']],
            ['GET', '/api/conversations/{convAB}/updates', [], ['anon', 'stranger']],
            ['GET', '/api/conversation-list', [], ['anon']],
            // (#214) la réponse cite un message : un étranger n'y gagne rien, même en devinant l'identifiant d'un message
            ['POST', '/api/conversations/{convAB}/messages', ['message' => 'Intrus', 'replyTo' => '1'], ['anon', 'stranger']],
            ['PATCH', '/api/conversations/{convAB}', ['title' => 'Piraté'], ['anon', 'stranger']],
            ['POST', '/api/conversations/{convAB}/typing', [], ['anon', 'stranger']],
            // Corbeille (#190) : seul l'initiateur ; la conversation du jeu d'essai n'en a pas, personne ne peut donc agir
            ['DELETE', '/api/conversations/{convAB}', [], ['anon', 'stranger', 'outsiderB', 'memberA']],
            ['POST', '/api/conversations/{convAB}/restore', [], ['anon', 'stranger', 'outsiderB', 'memberA']],
            ['DELETE', '/api/conversations/{convAB}/permanent', [], ['anon', 'stranger', 'outsiderB', 'memberA']],
            ['PATCH', '/api/conversations/{convAB}/messages/1', ['message' => 'Piraté'], ['anon', 'stranger', 'outsiderB']],
            ['POST', '/api/conversation-alerts/1/dismiss', [], ['anon']],
            // Mentions (#178) : la recherche exige un contexte (conversation ou groupes), le retrait un invité existant
            ['GET', '/api/members', [], ['anon', 'stranger']],
            ['DELETE', '/api/conversations/{convAB}/guests/{userMemberA}', [], ['anon', 'stranger', 'outsiderB', 'memberA']],
            ['POST', '/api/conversations', ['groupId' => '{groupA}', 'targetGroupId' => '{groupB}', 'message' => 'Je parle pour A'], ['anon', 'stranger', 'outsiderB']],
            // Documents
            ['GET', '/api/groups/{groupA}/documents', [], ['anon', 'stranger', 'outsiderB']],
            ['POST', '/api/groups/{groupA}/documents', ['__upload' => true], ['anon', 'stranger', 'outsiderB', 'memberA']],
            ['GET', '/api/documents/{docA}', [], ['anon', 'stranger', 'outsiderB']],
            ['DELETE', '/api/documents/{docA}', [], ['anon', 'stranger', 'outsiderB', 'memberA']],
        ];

        foreach ($routes as [$method, $path, $body, $denied]) {
            foreach ($denied as $actor) {
                yield "{$actor} {$method} {$path}" => [$actor, $method, $path, $body];
            }
        }
    }

    #[Test]
    #[DataProvider('deniedAccessProvider')]
    public function testDeniedActorIsRefused(string $actor, string $method, string $path, array $body): void
    {
        [$status] = $this->call($actor, $method, $path, $body);

        $adminRoute = str_starts_with($path, '/api/admin/');
        $expected = $actor === 'anon' ? 401 : 403;
        self::assertSame($expected, $status, "{$actor} {$method} {$path}" . ($adminRoute ? ' (route admin)' : ''));
    }

    // --- Un objet interdit et un objet inexistant sont indiscernables ----------

    /** @return iterable<string, array{0: string, 1: string, 2: array<string, mixed>, 3: string}> */
    public static function resourceRoutesProvider(): iterable
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');

        $routes = [
            ['GET', '/api/availability/pending/{id}', [], 'groupA'],
            ['GET', '/api/availability/requested/{id}', [], 'groupA'],
            ['POST', '/api/availability/{id}/respond', ['accepted' => true], 'excBA'],
            ['PATCH', '/api/availability/{id}', ['occurrenceDate' => $future], 'excBA'],
            ['DELETE', '/api/availability/{id}', [], 'excBA'],
            ['PATCH', '/api/admin/users/{id}', ['active' => false], 'userMemberA'],
            ['POST', '/api/admin/users/{id}/unlock', [], 'userMemberA'],
            ['GET', '/api/groups/{id}/space', [], 'groupA'],
            ['PATCH', '/api/groups/{id}/space', ['lineup' => [], 'upcomingShows' => []], 'groupA'],
            ['GET', '/api/groups/{id}/documents', [], 'groupA'],
            ['POST', '/api/groups/{id}/documents', ['__upload' => true], 'groupA'],
            ['GET', '/api/documents/{id}', [], 'docA'],
            ['DELETE', '/api/documents/{id}', [], 'docA'],
            ['GET', '/api/conversations/{id}/updates', [], 'convAB'],
            ['POST', '/api/conversations/{id}/messages', ['message' => 'Intrus'], 'convAB'],
            ['PATCH', '/api/conversations/{id}', ['title' => 'Piraté'], 'convAB'],
            ['POST', '/api/conversations/{id}/typing', [], 'convAB'],
            ['DELETE', '/api/conversations/{id}', [], 'convAB'],
            ['POST', '/api/conversations/{id}/restore', [], 'convAB'],
            ['DELETE', '/api/conversations/{id}/permanent', [], 'convAB'],
            ['DELETE', '/api/conversations/{id}/guests/1', [], 'convAB'],
            ['PATCH', '/api/conversations/{id}/messages/1', ['message' => 'Piraté'], 'convAB'],
        ];

        foreach ($routes as [$method, $path, $body, $idKey]) {
            yield "{$method} {$path}" => [$method, $path, $body, $idKey];
        }
    }

    #[Test]
    #[DataProvider('resourceRoutesProvider')]
    public function testMissingIdIsIndistinguishableFromForbiddenOne(string $method, string $path, array $body, string $idKey): void
    {
        // stranger n'a aucun lien avec A ni avec B : toute ressource lui est interdite.
        $forbidden = $this->call('stranger', $method, str_replace('{id}', (string) self::$ids[$idKey], $path), $body);
        $missing = $this->call('stranger', $method, str_replace('{id}', self::MISSING_ID, $path), $body);

        self::assertSame($forbidden, $missing, "{$method} {$path} : l'existence de l'identifiant ne doit pas se déduire de la réponse.");
    }

    #[Test]
    #[DataProvider('resourceRoutesProvider')]
    public function testEdgeCaseIdsNeverCauseServerErrors(string $method, string $path, array $body, string $idKey): void
    {
        foreach (['0', '-1', 'abc', '99999999999999999999', '1.5', '%00'] as $edge) {
            [$status] = $this->call('stranger', $method, str_replace('{id}', $edge, $path), $body);

            self::assertLessThan(500, $status, "{$method} {$path} ({$idKey}) avec l'id « {$edge} » ne doit pas faire une erreur serveur.");
            self::assertNotSame(200, $status, "{$method} {$path} ({$idKey}) avec l'id « {$edge} » ne doit pas réussir pour un inconnu.");
        }
    }

    #[Test]
    public function testMessagesPagesAreRefusedToOutsidersAndIndistinguishableFromMissingOnes(): void
    {
        $conversation = (string) self::$ids['convAB'];

        [$anonStatus, $anonBody] = $this->call('anon', 'GET', "/messages/{$conversation}");
        self::assertSame(302, $anonStatus, 'anonyme : redirigé vers la connexion — ' . $anonBody);

        $forbidden = $this->call('stranger', 'GET', "/messages/{$conversation}");
        $missing = $this->call('stranger', 'GET', '/messages/' . self::MISSING_ID);
        $malformed = $this->call('stranger', 'GET', '/messages/abc');
        self::assertSame(403, $forbidden[0]);
        self::assertSame($forbidden, $missing, 'interdit et inexistant indiscernables');
        self::assertSame($forbidden, $malformed);
        self::assertStringNotContainsString('Secret entre A et B', $forbidden[1]);

        self::assertSame(200, $this->call('outsiderB', 'GET', "/messages/{$conversation}")[0], 'membre du groupe visé');
        self::assertSame(200, $this->call('stranger', 'GET', '/messages')[0], 'la liste est ouverte à tout connecté (vide pour un inconnu)');
    }

    #[Test]
    public function testNewConversationPageIsLoginOnlyNeverCreatesAnythingAndLeaksNoContactAddress(): void
    {
        $groupA = (string) self::$ids['groupA'];

        self::assertSame(302, $this->call('anon', 'GET', "/messages/new/{$groupA}")[0], 'anonyme : redirigé vers la connexion');

        $unknown = $this->call('stranger', 'GET', '/messages/new/' . self::MISSING_ID);
        $malformed = $this->call('stranger', 'GET', '/messages/new/abc');
        self::assertSame(403, $unknown[0]);
        self::assertSame($unknown, $malformed, 'groupe inexistant et identifiant mal formé indiscernables');

        [$status, $body] = $this->call('outsiderB', 'GET', "/messages/new/{$groupA}");
        self::assertSame(200, $status);
        self::assertStringContainsString('data-draft-target-id="' . $groupA . '"', $body);
        self::assertStringNotContainsString('a@example.test', $body, 'l\'adresse de contact du groupe ne sort jamais');

        [, $strangerBody] = $this->call('stranger', 'GET', "/messages/new/{$groupA}");
        self::assertStringContainsString('data-draft-blocked', $strangerBody, 'sans groupe, on ne peut pas écrire (le serveur refusera aussi à l\'envoi)');
        self::assertSame(1, $this->conversationCount(), 'aucune page de démarrage ne crée de conversation');
    }

    // --- Garde-fous : la matrice n'est pas vide de sens -------------------------

    #[Test]
    public function testLegitimateActorsStillHaveAccess(): void
    {
        $groupA = (string) self::$ids['groupA'];

        self::assertSame(200, $this->call('memberA', 'GET', "/api/groups/{$groupA}/space")[0]);
        self::assertSame(200, $this->call('memberA', 'GET', "/api/groups/{$groupA}/documents")[0]);
        self::assertSame(200, $this->call('memberA', 'GET', "/api/availability/pending/{$groupA}")[0]);
        self::assertSame(200, $this->call('outsiderB', 'GET', '/api/availability/requested/' . $this->groupBId())[0]);
        self::assertSame(200, $this->call('memberA', 'GET', '/api/conversations')[0]);
        self::assertSame(200, $this->call('outsiderB', 'GET', '/api/conversations')[0]);
        self::assertSame(200, $this->call('outsiderB', 'GET', '/api/conversations/' . self::$ids['convAB'] . '/updates')[0], 'membre du groupe visé');
        self::assertSame(200, $this->call('outsiderB', 'GET', '/api/conversation-list')[0]);
        self::assertSame([], json_decode($this->call('stranger', 'GET', '/api/conversations')[1], true)['conversations'], 'un inconnu ne voit aucun fil');
    }

    #[Test]
    public function testGroupSpacePageHidesDocumentsFromNonMembers(): void
    {
        foreach (['anon', 'stranger', 'outsiderB'] as $actor) {
            [, $body] = $this->call($actor, 'GET', '/groups/' . self::$ids['slugA'] . '/space');

            self::assertStringNotContainsString('secret-de-A.pdf', $body, "{$actor} ne doit pas voir les documents du groupe A.");
            self::assertStringNotContainsString('stored-secret', $body);
        }
    }

    // --- Harnais ------------------------------------------------------------------

    private function conversationCount(): int
    {
        return (int) TestDatabase::connection()->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
    }

    private function groupBId(): int
    {
        return (int) TestDatabase::connection()->query("SELECT id FROM `groups` WHERE name = 'Groupe B'")->fetchColumn();
    }

    /** @return array{0: int, 1: string} statut et corps de la réponse */
    private function call(string $actor, string $method, string $path, array $body = []): array
    {
        [$kernel, $container] = $this->actor($actor);

        foreach (self::$ids as $key => $value) {
            $path = str_replace('{' . $key . '}', (string) $value, $path);
        }

        array_walk_recursive($body, static function (mixed &$value): void {
            if (is_string($value) && preg_match('/^\{(\w+)\}$/', $value, $m) === 1 && isset(self::$ids[$m[1]])) {
                $value = (string) self::$ids[$m[1]];
            }
        });

        $files = [];
        if (($body['__upload'] ?? false) === true) {
            unset($body['__upload']);
            $tmp = tempnam(self::$storagePath, 'up');
            file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
            $files['document'] = ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        }

        $headers = ['X-CSRF-Token' => $container->get(CsrfTokenManager::class)->getToken()];
        $request = new Request($method, $path, [], $body, $headers, $files);

        try {
            $response = $kernel->handle($request);
        } catch (\Throwable $e) {
            return [500, $e::class . ': ' . $e->getMessage()];
        }

        return [$response->statusCode(), $response->body()];
    }

    /** @return array{0: Kernel, 1: Container, 2: SessionInterface} */
    private function actor(string $name): array
    {
        if (isset(self::$actors[$name])) {
            return self::$actors[$name];
        }

        $config = require __DIR__ . '/../../config/config.php';
        $config['db'] = [
            'host' => getenv('DB_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_TEST_PORT') ?: '3307',
            'name' => getenv('DB_TEST_NAME') ?: 'rehearsalbox_test',
            'user' => getenv('DB_TEST_USER') ?: 'root',
            'password' => getenv('DB_TEST_PASSWORD') ?: 'root',
        ];
        $config['storage']['group_documents_path'] = self::$storagePath;

        $container = (require __DIR__ . '/../../config/services.php')($config);
        $session = new InMemorySession();
        $container->set(SessionInterface::class, static fn () => $session);
        $container->set(MailerInterface::class, static fn () => new RecordingMailer());

        $routeGroups = require __DIR__ . '/../../config/routes.php';
        $router = new Router();
        foreach ([...$routeGroups['pages'], ...$routeGroups['api']] as [$method, $pattern, $handler]) {
            $router->add($method, $pattern, $handler);
        }

        if ($name !== 'anon') {
            $container->get(AuthServiceInterface::class)->attempt("{$name}@rehearsalbox.test", 'password');
        }

        return self::$actors[$name] = [
            new Kernel($router, $container, $container->get(CsrfTokenManager::class)),
            $container,
            $session,
        ];
    }
}
