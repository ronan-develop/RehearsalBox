<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Service;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Assembly\Entity\AssemblyStatus;
use App\Assembly\Exception\AssemblyLockedException;
use App\Assembly\Exception\AssemblyNotFoundException;
use App\Assembly\Exception\AssemblyValidationException;
use App\Assembly\Repository\MysqlAssemblyContentRepository;
use App\Assembly\Repository\MysqlAssemblyRepository;
use App\Security\AdminAccess;
use App\Assembly\Service\AssemblyBoardRoster;
use App\Assembly\Service\AssemblyCompleteness;
use App\Assembly\Service\AssemblyContentResolver;
use App\Assembly\Service\AssemblyInputPolicy;
use App\Assembly\Service\AssemblyReader;
use App\Assembly\Service\AssemblyService;
use App\Database\TransactionRunner;
use App\Security\Exception\AccessDeniedException;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

/** #361 : droits, cycle brouillon → validé → correction, gel d'un compte rendu validé, rollback et noms relus en base. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class AssemblyServiceTest extends RepositoryTestCase
{
    use MessagingScenario;

    private AssemblyService $service;
    private AssemblyReader $reader;
    private MysqlAssemblyRepository $assemblies;
    private MysqlAssemblyContentRepository $contents;
    private User $admin;
    private User $president;
    private User $treasurer;
    private User $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->assemblies = new MysqlAssemblyRepository($this->pdo);
        $this->contents = new MysqlAssemblyContentRepository($this->pdo);
        $access = new AdminAccess();
        $this->service = new AssemblyService(
            $access,
            new AssemblyInputPolicy(),
            new AssemblyCompleteness(),
            $this->assemblies,
            $this->contents,
            new AssemblyContentResolver($this->users, new AssemblyBoardRoster([])),
            new TransactionRunner($this->pdo),
            new NullLogger(),
        );
        $this->reader = new AssemblyReader($access, $this->assemblies, $this->contents, $this->users);
        $this->admin = $this->makeAdmin('Admin');
        $this->president = $this->user('Pierre');
        $this->treasurer = $this->user('Thomas');
        $this->secretary = $this->user('Sophie');
    }

    private function makeAdmin(string $name): User
    {
        return $this->users->save(new User(0, strtolower($name) . '@rehearsalbox.test', 'hash', $name, UserRole::Admin, true, 0, null));
    }

    /** @return array<string, mixed> un document complet, validable */
    private function input(array $override = []): array
    {
        return $override + [
            'kind' => 'ordinaire',
            'heldOn' => '2026-10-10',
            'location' => 'Le local',
            'openedAt' => '18:30',
            'closedAt' => '20:15',
            'quorumReached' => true,
            'board' => ['president' => $this->president->id(), 'tresorier' => $this->treasurer->id(), 'secretaire' => $this->secretary->id()],
            'attendees' => [
                ['userId' => $this->president->id(), 'name' => null, 'attendance' => 'present', 'proxyHolder' => null],
                ['userId' => null, 'name' => 'Invité <b>libre</b>', 'attendance' => 'represente', 'proxyHolder' => 'Pierre'],
            ],
            'agenda' => [['title' => 'Bilan', 'discussion' => 'Tout va bien.']],
            'resolutions' => [['text' => 'Approuver le bilan', 'for' => 3, 'against' => 0, 'abstain' => 1, 'outcome' => 'adoptee']],
            'moralReport' => 'Rapport moral',
            'financialReport' => null,
            'otherBusiness' => null,
        ];
    }

    private function draftId(): int
    {
        return $this->service->create($this->admin, ['kind' => 'ordinaire', 'heldOn' => '2026-10-10'], $this->now);
    }

    private function savedDraft(): int
    {
        $id = $this->draftId();
        $this->service->saveDraft($this->admin, $id, 0, $this->input(), $this->now);

        return $id;
    }

    private function validatedId(): int
    {
        $id = $this->savedDraft();
        $this->service->validate($this->admin, $id, 1, $this->now);

        return $id;
    }

    #[Test]
    public function testNonAdminsAndInactiveAdminsAreDeniedEverywhere(): void
    {
        $id = $this->validatedId();
        $inactive = $this->users->save($this->makeAdmin('Gone')->withActive(false));
        $calls = [
            'create' => fn (User $u) => $this->service->create($u, ['kind' => 'ordinaire', 'heldOn' => '2026-10-10']),
            'save' => fn (User $u) => $this->service->saveDraft($u, $id, 2, $this->input()),
            'validate' => fn (User $u) => $this->service->validate($u, $id, 2),
            'correct' => fn (User $u) => $this->service->correct($u, $id, 'Motif'),
            'delete' => fn (User $u) => $this->service->deleteDraft($u, $id),
            'list' => fn (User $u) => $this->reader->list($u),
            'minutes' => fn (User $u) => $this->reader->minutes($u, $id),
            'candidates' => fn (User $u) => $this->reader->candidates($u),
        ];

        foreach ([$this->president, $inactive] as $actor) {
            foreach ($calls as $name => $call) {
                try {
                    $call($actor);
                    self::fail("{$name} aurait dû être refusé");
                } catch (AccessDeniedException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertCount(1, $this->assemblies->listHeads());
    }

    #[Test]
    public function testCreateSaveValidateAndReadBack(): void
    {
        $id = $this->draftId();
        self::assertSame(1, $this->service->saveDraft($this->admin, $id, 0, $this->input(), $this->now));
        $this->service->validate($this->admin, $id, 1, $this->now);

        $minutes = $this->reader->minutes($this->admin, $id);
        self::assertSame(AssemblyStatus::Valide, $minutes['assembly']->status);
        self::assertSame('Le local', $minutes['assembly']->location);
        self::assertSame('Pierre', $minutes['content']->board['president']->name);
        self::assertCount(2, $minutes['content']->attendees);
        self::assertSame('Pierre', $minutes['content']->attendees[0]->name);
        self::assertSame('Invité <b>libre</b>', $minutes['content']->attendees[1]->name);
        self::assertNull($minutes['successor']);
        self::assertCount(1, $this->reader->list($this->admin));
    }

    #[Test]
    public function testAValidatedAssemblyCanNeitherBeSavedValidatedAgainNorDeleted(): void
    {
        $id = $this->validatedId();
        $before = $this->reader->minutes($this->admin, $id);

        foreach ([
            fn () => $this->service->saveDraft($this->admin, $id, 2, $this->input(['location' => 'Pirate'])),
            fn () => $this->service->validate($this->admin, $id, 2),
            fn () => $this->service->deleteDraft($this->admin, $id),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('Un compte rendu validé a été modifié');
            } catch (AssemblyLockedException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertEquals($before, $this->reader->minutes($this->admin, $id));
    }

    #[Test]
    public function testAnIncompleteDraftCannotBeValidated(): void
    {
        $id = $this->draftId();
        $this->service->saveDraft($this->admin, $id, 0, $this->input(['location' => null, 'board' => [], 'agenda' => []]), $this->now);

        try {
            $this->service->validate($this->admin, $id, 1, $this->now);
            self::fail('Validation acceptée');
        } catch (AssemblyValidationException $e) {
            self::assertArrayHasKey('location', $e->fields());
            self::assertArrayHasKey('board.president', $e->fields());
            self::assertArrayHasKey('agenda', $e->fields());
        }
        self::assertTrue($this->assemblies->find($id)?->isDraft());
    }

    #[Test]
    public function testAStaleRevisionIsRefusedAndNothingIsWritten(): void
    {
        $id = $this->savedDraft();

        $this->expectException(AssemblyLockedException::class);
        try {
            $this->service->saveDraft($this->admin, $id, 0, $this->input(['location' => 'Écrasement']), $this->now);
        } finally {
            self::assertSame('Le local', $this->assemblies->find($id)?->location);
        }
    }

    #[Test]
    public function testCreationNeedsAKindAndARealDate(): void
    {
        foreach ([[], ['kind' => 'autre', 'heldOn' => '2026-10-10'], ['kind' => 'ordinaire', 'heldOn' => '2026-02-30']] as $input) {
            try {
                $this->service->create($this->admin, $input);
                self::fail('Création refusée attendue');
            } catch (AssemblyValidationException $e) {
                self::assertNotSame([], $e->fields());
            }
        }
        self::assertSame([], $this->assemblies->listHeads());
    }

    #[Test]
    public function testUnknownAssembliesAreNotFound(): void
    {
        $this->expectException(AssemblyNotFoundException::class);
        $this->service->saveDraft($this->admin, 999999, 0, $this->input());
    }

    #[Test]
    public function testNamesAreReadFromTheDatabaseAndStayFrozenWhenTheAccountIsRenamed(): void
    {
        $id = $this->validatedId();
        $this->users->save($this->president->withDisplayName('Nouveau Nom'));

        self::assertSame('Pierre', $this->reader->minutes($this->admin, $id)['content']->board['president']->name);
    }

    private function serviceWithRoster(AssemblyBoardRoster $roster): AssemblyService
    {
        return new AssemblyService(
            new AdminAccess(),
            new AssemblyInputPolicy(),
            new AssemblyCompleteness(),
            $this->assemblies,
            $this->contents,
            new AssemblyContentResolver($this->users, $roster),
            new TransactionRunner($this->pdo),
            new NullLogger(),
        );
    }

    #[Test]
    public function testARestrictedPostRefusesAnyOtherAccountServerSide(): void
    {
        $service = $this->serviceWithRoster(new AssemblyBoardRoster(['president' => $this->president->email(), 'tresorier' => $this->treasurer->email()]));
        $id = $service->create($this->admin, ['kind' => 'ordinaire', 'heldOn' => '2026-10-10'], $this->now);
        $input = $this->input(['board' => ['president' => $this->secretary->id(), 'tresorier' => $this->treasurer->id(), 'secretaire' => $this->president->id()]]);

        try {
            $service->saveDraft($this->admin, $id, 0, $input, $this->now);
            self::fail('Le poste restreint doit refuser un autre compte');
        } catch (AssemblyValidationException $e) {
            self::assertSame(['board.president'], array_keys($e->fields()));
        }
        self::assertSame(0, $this->assemblies->find($id)?->revision);

        self::assertSame(1, $service->saveDraft($this->admin, $id, 0, $this->input(), $this->now));
    }

    #[Test]
    public function testACorrectionKeepsAFormerHolderOfARestrictedPost(): void
    {
        $id = $this->validatedId();
        $service = $this->serviceWithRoster(new AssemblyBoardRoster(['president' => $this->secretary->email()]));
        $correction = $service->correct($this->admin, $id, 'Motif', $this->now);

        // Le président de la version validée n'est plus le titulaire configuré : il reste accepté, il figure déjà dans le contenu.
        self::assertSame(1, $service->saveDraft($this->admin, $correction, 0, $this->input(), $this->now));
        // Mais personne d'autre que le titulaire configuré ou l'ancien titulaire.
        $this->expectException(AssemblyValidationException::class);
        $service->saveDraft($this->admin, $correction, 1, $this->input(['board' => ['president' => $this->treasurer->id(), 'tresorier' => $this->treasurer->id() + 100, 'secretaire' => $this->secretary->id()]]), $this->now);
    }

    #[Test]
    public function testAnUnknownOrInactiveAccountIsRefusedWithItsField(): void
    {
        $gone = $this->users->save($this->user('Gone')->withActive(false));
        $id = $this->draftId();

        try {
            $this->service->saveDraft($this->admin, $id, 0, $this->input(['board' => ['president' => $gone->id(), 'tresorier' => 999999]]), $this->now);
            self::fail('Comptes refusés attendus');
        } catch (AssemblyValidationException $e) {
            self::assertArrayHasKey('board.president', $e->fields());
            self::assertArrayHasKey('board.tresorier', $e->fields());
        }
        self::assertSame(0, $this->assemblies->find($id)?->revision);
        self::assertSame([], $this->contents->load($id)->board);
    }

    #[Test]
    public function testACorrectionCopiesTheContentAndKeepsADeactivatedBoardMember(): void
    {
        $source = $this->validatedId();
        $this->users->save($this->treasurer->withActive(false));

        $correction = $this->service->correct($this->admin, $source, '  Nom du lieu corrigé ', $this->now);

        $copy = $this->reader->minutes($this->admin, $correction);
        self::assertTrue($copy['assembly']->isDraft());
        self::assertSame(2, $copy['assembly']->version);
        self::assertSame('Nom du lieu corrigé', $copy['assembly']->correctionReason);
        self::assertEquals($this->contents->load($source), $copy['content']);
        self::assertSame($correction, $this->reader->minutes($this->admin, $source)['successor']?->id);
        // Le titulaire désactivé est déjà dans le contenu : l'enregistrement de la correction reste possible.
        self::assertSame(1, $this->service->saveDraft($this->admin, $correction, 0, $this->input(['location' => 'Nouveau lieu']), $this->now));
        self::assertSame('Nouveau lieu', $this->assemblies->find($correction)?->location);
        self::assertSame('Le local', $this->assemblies->find($source)?->location);
        self::assertCount(1, $this->reader->list($this->admin));
    }

    #[Test]
    public function testACorrectionNeedsAReasonAndAValidatedHeadWithoutSuccessor(): void
    {
        $source = $this->validatedId();
        $draft = $this->savedDraft();

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            try {
                $this->service->correct($this->admin, $source, $reason);
                self::fail('Motif refusé attendu');
            } catch (AssemblyValidationException $e) {
                self::assertArrayHasKey('reason', $e->fields());
            }
        }
        $this->service->correct($this->admin, $source, 'Première', $this->now);
        foreach ([$source, $draft] as $notCorrectable) {
            try {
                $this->service->correct($this->admin, $notCorrectable, 'Seconde');
                self::fail('Correction refusée attendue');
            } catch (AssemblyLockedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function testADraftCanBeDeleted(): void
    {
        $id = $this->savedDraft();

        $this->service->deleteDraft($this->admin, $id);

        self::assertNull($this->assemblies->find($id));
        $this->expectException(AssemblyNotFoundException::class);
        $this->service->deleteDraft($this->admin, $id);
    }

    #[Test]
    public function testAFailureWhileWritingTheContentRollsTheHeaderBack(): void
    {
        $id = $this->draftId();
        $failing = new class ($this->contents) implements \App\Assembly\Repository\AssemblyContentRepositoryInterface {
            public function __construct(private readonly \App\Assembly\Repository\AssemblyContentRepositoryInterface $inner)
            {
            }

            public function load(int $assemblyId): \App\Assembly\Entity\AssemblyContent
            {
                return $this->inner->load($assemblyId);
            }

            public function replace(int $assemblyId, \App\Assembly\Entity\AssemblyContent $content): void
            {
                throw new \RuntimeException('disque plein');
            }

            public function copy(int $fromId, int $toId): void
            {
                $this->inner->copy($fromId, $toId);
            }
        };
        $service = new AssemblyService(new AdminAccess(), new AssemblyInputPolicy(), new AssemblyCompleteness(), $this->assemblies, $failing, new AssemblyContentResolver($this->users, new AssemblyBoardRoster([])), new TransactionRunner($this->pdo), new NullLogger());

        try {
            $service->saveDraft($this->admin, $id, 0, $this->input(), $this->now);
            self::fail('Erreur attendue');
        } catch (\RuntimeException) {
            $assembly = $this->assemblies->find($id);
            self::assertSame(0, $assembly?->revision);
            self::assertNull($assembly?->location);
        }
    }
}
