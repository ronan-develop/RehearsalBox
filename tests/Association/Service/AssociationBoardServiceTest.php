<?php

declare(strict_types=1);

namespace App\Tests\Association\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Association\Exception\AssociationBoardNotFoundException;
use App\Association\Exception\AssociationBoardValidationException;
use App\Association\Repository\MysqlAssociationBoardRepository;
use App\Association\Service\AssociationBoardService;
use App\Security\AdminAccess;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #478 : le bureau (un compte et un prénom et nom par poste) se règle depuis l'administration. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AssociationBoardServiceTest extends RepositoryTestCase
{
    use MessagingScenario;

    private AssociationBoardService $service;
    private User $admin;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $repository = new MysqlAssociationBoardRepository($this->pdo);
        $this->service = new AssociationBoardService(new AdminAccess(), $repository, $this->users, new NullLogger());
        $this->admin = $this->users->save(new User(0, 'admin@rehearsalbox.test', 'hash', 'Admin', UserRole::Admin, true, 0, null));
        $this->alice = $this->user('Alice');
        $this->bob = $this->user('Bob');
    }

    #[Test]
    public function testOnlyActiveAdministratorsCanReadOrChangeTheBoard(): void
    {
        $gone = $this->users->save(new User(0, 'parti@rehearsalbox.test', 'hash', 'Parti', UserRole::Admin, false, 0, null));
        $calls = [
            fn (User $u) => $this->service->holders($u),
            fn (User $u) => $this->service->accounts($u),
            fn (User $u) => $this->service->assign($u, 'president', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin']),
            fn (User $u) => $this->service->vacate($u, 'president'),
        ];

        foreach ([$this->alice, $gone] as $actor) {
            foreach ($calls as $call) {
                try {
                    $call($actor);
                    self::fail('Accès refusé attendu');
                } catch (AccessDeniedException) {
                    self::addToAssertionCount(1);
                }
            }
        }
        self::assertSame([], $this->service->holders($this->admin));
    }

    #[Test]
    public function testAssigningAPostStoresTheAccountAndTheTrimmedFullName(): void
    {
        $this->service->assign($this->admin, 'president', ['userId' => $this->alice->id(), 'fullName' => '  Alice Martin '], $this->now);

        $holder = $this->service->holders($this->admin)['president'];
        self::assertSame($this->alice->id(), $holder->userId);
        self::assertSame('Alice Martin', $holder->fullName);
    }

    #[Test]
    public function testAssigningAgainReplacesTheHolderOfTheSamePost(): void
    {
        $this->service->assign($this->admin, 'tresorier', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin'], $this->now);
        $this->service->assign($this->admin, 'tresorier', ['userId' => $this->bob->id(), 'fullName' => 'Bob Durand'], $this->now);

        $holders = $this->service->holders($this->admin);
        self::assertCount(1, $holders);
        self::assertSame($this->bob->id(), $holders['tresorier']->userId);
    }

    #[Test]
    public function testAnAccountCannotHoldTwoPostsAndNothingIsWritten(): void
    {
        $this->service->assign($this->admin, 'president', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin'], $this->now);

        try {
            $this->service->assign($this->admin, 'secretaire', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin'], $this->now);
            self::fail('Un compte ne tient qu\'un poste');
        } catch (AssociationBoardValidationException $e) {
            self::assertSame(['userId'], array_keys($e->fields()));
        }
        self::assertSame(['president'], array_keys($this->service->holders($this->admin)));
    }

    #[Test]
    public function testReassigningTheSamePostToTheSameAccountIsNotACollision(): void
    {
        $this->service->assign($this->admin, 'president', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin'], $this->now);
        $this->service->assign($this->admin, 'president', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin-Durand'], $this->now);

        self::assertSame('Alice Martin-Durand', $this->service->holders($this->admin)['president']->fullName);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function badInputs(): iterable
    {
        yield 'nom vide' => [['userId' => 1, 'fullName' => '   '], 'fullName'];
        yield 'nom absent' => [['userId' => 1], 'fullName'];
        yield 'nom trop long' => [['userId' => 1, 'fullName' => str_repeat('a', 121)], 'fullName'];
        yield 'nom sur deux lignes' => [['userId' => 1, 'fullName' => "A\nB"], 'fullName'];
        yield 'nom non texte' => [['userId' => 1, 'fullName' => ['x']], 'fullName'];
        yield 'compte absent' => [['fullName' => 'Alice Martin'], 'userId'];
        yield 'compte non numérique' => [['userId' => '1', 'fullName' => 'Alice Martin'], 'userId'];
        yield 'compte inexistant' => [['userId' => 999999, 'fullName' => 'Alice Martin'], 'userId'];
    }

    /** @param array<string, mixed> $input */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function testInvalidInputIsRefusedPerFieldAndNothingIsWritten(array $input, string $field): void
    {
        if (($input['userId'] ?? null) === 1) {
            $input['userId'] = $this->alice->id();
        }

        try {
            $this->service->assign($this->admin, 'president', $input, $this->now);
            self::fail('Saisie refusée attendue');
        } catch (AssociationBoardValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
        self::assertSame([], $this->service->holders($this->admin));
    }

    #[Test]
    public function testAnInactiveAccountCannotBeAssigned(): void
    {
        $gone = $this->users->save($this->bob->withActive(false));

        $this->expectException(AssociationBoardValidationException::class);
        $this->service->assign($this->admin, 'president', ['userId' => $gone->id(), 'fullName' => 'Bob Durand'], $this->now);
    }

    #[Test]
    public function testAnUnknownPostIsNotFoundWhateverTheInput(): void
    {
        foreach (['vice_president', '', 'PRESIDENT', "president\n"] as $post) {
            try {
                $this->service->assign($this->admin, $post, ['userId' => $this->alice->id(), 'fullName' => 'Alice'], $this->now);
                self::fail('Poste inconnu attendu');
            } catch (AssociationBoardNotFoundException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(AssociationBoardNotFoundException::class);
        $this->service->vacate($this->admin, 'chef');
    }

    #[Test]
    public function testVacatingAPostEmptiesIt(): void
    {
        $this->service->assign($this->admin, 'president', ['userId' => $this->alice->id(), 'fullName' => 'Alice Martin'], $this->now);
        $this->service->vacate($this->admin, 'president');
        $this->service->vacate($this->admin, 'president');

        self::assertSame([], $this->service->holders($this->admin));
    }

    #[Test]
    public function testAccountsAreTheActiveOnesOnly(): void
    {
        $this->users->save($this->bob->withActive(false));

        $names = array_map(static fn (User $u): string => $u->displayName(), $this->service->accounts($this->admin));

        self::assertContains('Alice', $names);
        self::assertNotContains('Bob', $names);
    }
}
