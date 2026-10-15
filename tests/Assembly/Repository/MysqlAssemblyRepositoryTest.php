<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Repository;

use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Entity\AssemblyStatus;
use App\Assembly\Exception\AssemblyLockedException;
use App\Assembly\Repository\MysqlAssemblyRepository;
use App\Assembly\Service\AssemblyDraft;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** #361 : en-tête des comptes rendus d'AG. Le gel d'un compte rendu validé repose sur ces écritures atomiques (statut + révision dans le WHERE). */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlAssemblyRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlAssemblyRepository $assemblies;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->assemblies = new MysqlAssemblyRepository($this->pdo);
        $this->adminId = $this->user('Admin')->id();
    }

    private function draft(string $location = 'Local', ?string $moral = null): AssemblyDraft
    {
        return new AssemblyDraft(AssemblyKind::Extraordinaire, new \DateTimeImmutable('2026-11-02'), $location, '18:30', '20:15', true, $moral, null, null, [], [], [], []);
    }

    private function created(): int
    {
        return $this->assemblies->insert(AssemblyKind::Ordinaire, new \DateTimeImmutable('2026-10-10'), $this->adminId, $this->now);
    }

    private function validated(): int
    {
        $id = $this->created();
        self::assertTrue($this->assemblies->markValidated($id, 0, $this->adminId, $this->now));

        return $id;
    }

    #[Test]
    public function testAnInsertedAssemblyIsAFirstVersionDraftAndRoundTrips(): void
    {
        $assembly = $this->assemblies->find($this->created());

        self::assertNotNull($assembly);
        self::assertSame(AssemblyKind::Ordinaire, $assembly->kind);
        self::assertSame('2026-10-10', $assembly->heldOn->format('Y-m-d'));
        self::assertSame(AssemblyStatus::Brouillon, $assembly->status);
        self::assertTrue($assembly->isDraft());
        self::assertSame(1, $assembly->version);
        self::assertSame(0, $assembly->revision);
        self::assertNull($assembly->previousId);
        self::assertNull($assembly->validatedAt);
        self::assertNull($assembly->quorumReached);
        self::assertNull($this->assemblies->find(999999));
    }

    #[Test]
    public function testUpdatingADraftWritesTheHeaderAndBumpsTheRevision(): void
    {
        $id = $this->created();

        self::assertTrue($this->assemblies->updateDraftHeader($id, 0, $this->draft('Salle d\'<b>essai</b>', 'Un rapport'), $this->now));

        $assembly = $this->assemblies->find($id);
        self::assertNotNull($assembly);
        self::assertSame(AssemblyKind::Extraordinaire, $assembly->kind);
        self::assertSame('2026-11-02', $assembly->heldOn->format('Y-m-d'));
        self::assertSame('Salle d\'<b>essai</b>', $assembly->location);
        self::assertSame('18:30', $assembly->openedAt);
        self::assertSame('20:15', $assembly->closedAt);
        self::assertTrue($assembly->quorumReached);
        self::assertSame('Un rapport', $assembly->moralReport);
        self::assertSame(1, $assembly->revision);
    }

    #[Test]
    public function testAStaleRevisionWritesNothing(): void
    {
        $id = $this->created();
        $this->assemblies->updateDraftHeader($id, 0, $this->draft('Premier'), $this->now);

        self::assertFalse($this->assemblies->updateDraftHeader($id, 0, $this->draft('Second'), $this->now));
        self::assertFalse($this->assemblies->markValidated($id, 0, $this->adminId, $this->now));

        $assembly = $this->assemblies->find($id);
        self::assertNotNull($assembly);
        self::assertSame('Premier', $assembly->location);
        self::assertTrue($assembly->isDraft());
    }

    #[Test]
    public function testAValidatedAssemblyCannotBeUpdatedValidatedAgainOrDeleted(): void
    {
        $id = $this->validated();
        $validated = $this->assemblies->find($id);
        self::assertNotNull($validated);
        self::assertSame(AssemblyStatus::Valide, $validated->status);
        self::assertNotNull($validated->validatedAt);

        self::assertFalse($this->assemblies->updateDraftHeader($id, 0, $this->draft('Pirate'), $this->now));
        self::assertFalse($this->assemblies->updateDraftHeader($id, 1, $this->draft('Pirate'), $this->now));
        self::assertFalse($this->assemblies->markValidated($id, 0, $this->adminId, $this->now));
        self::assertFalse($this->assemblies->deleteDraft($id));

        $after = $this->assemblies->find($id);
        self::assertNotNull($after);
        self::assertNull($after->location);
        self::assertSame(AssemblyStatus::Valide, $after->status);
    }

    #[Test]
    public function testADraftCanBeDeletedButNotAnUnknownOne(): void
    {
        $id = $this->created();

        self::assertTrue($this->assemblies->deleteDraft($id));
        self::assertNull($this->assemblies->find($id));
        self::assertFalse($this->assemblies->deleteDraft($id));
    }

    #[Test]
    public function testLockReportsStatusRevisionVersionAndSuccessor(): void
    {
        $id = $this->validated();

        $lock = $this->assemblies->lock($id);
        self::assertNotNull($lock);
        self::assertSame(AssemblyStatus::Valide, $lock->status);
        self::assertSame(1, $lock->revision);
        self::assertSame(1, $lock->version);
        self::assertFalse($lock->hasSuccessor);

        $this->assemblies->insertCorrection($id, 'Faute de frappe', $this->adminId, $this->now);
        $withSuccessor = $this->assemblies->lock($id);
        self::assertNotNull($withSuccessor);
        self::assertTrue($withSuccessor->hasSuccessor);
        self::assertNull($this->assemblies->lock(999999));
    }

    #[Test]
    public function testACorrectionIsADraftNextVersionLinkedToItsSource(): void
    {
        $source = $this->validated();
        $this->assemblies->updateDraftHeader($source, 1, $this->draft(), $this->now); // refusé : validé
        $id = $this->assemblies->insertCorrection($source, 'Nom du lieu corrigé', $this->adminId, $this->now);

        $correction = $this->assemblies->find($id);
        self::assertNotNull($correction);
        self::assertTrue($correction->isDraft());
        self::assertSame(2, $correction->version);
        self::assertSame($source, $correction->previousId);
        self::assertSame('Nom du lieu corrigé', $correction->correctionReason);
        self::assertSame(0, $correction->revision);
        self::assertSame($this->assemblies->find($source)?->kind, $correction->kind);
        self::assertSame($id, $this->assemblies->successorOf($source)?->id);
        self::assertNull($this->assemblies->successorOf($id));
    }

    #[Test]
    public function testASecondCorrectionOfTheSameVersionIsRefused(): void
    {
        $source = $this->validated();
        $this->assemblies->insertCorrection($source, 'Première', $this->adminId, $this->now);

        $this->expectException(AssemblyLockedException::class);
        $this->assemblies->insertCorrection($source, 'Seconde', $this->adminId, $this->now);
    }

    #[Test]
    public function testADraftCannotBeCorrected(): void
    {
        $this->expectException(AssemblyLockedException::class);
        $this->assemblies->insertCorrection($this->created(), 'Trop tôt', $this->adminId, $this->now);
    }

    #[Test]
    public function testDeletingACorrectionDraftFreesTheChain(): void
    {
        $source = $this->validated();
        $correction = $this->assemblies->insertCorrection($source, 'Première', $this->adminId, $this->now);
        self::assertTrue($this->assemblies->deleteDraft($correction));

        self::assertNotSame(0, $this->assemblies->insertCorrection($source, 'Seconde', $this->adminId, $this->now));
    }

    #[Test]
    public function testHeadsExcludeReplacedVersionsAndComeNewestFirst(): void
    {
        $old = $this->validated();
        $this->assemblies->insertCorrection($old, 'Correction', $this->adminId, $this->now);
        $other = $this->assemblies->insert(AssemblyKind::Extraordinaire, new \DateTimeImmutable('2026-12-01'), $this->adminId, $this->now);

        $heads = array_map(static fn ($a) => $a->id, $this->assemblies->listHeads());

        self::assertNotContains($old, $heads);
        self::assertCount(2, $heads);
        self::assertSame($other, $heads[0]);
    }
}
