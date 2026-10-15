<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Presenter;

use App\Account\Entity\User;
use App\Account\Entity\UserRole;
use App\Assembly\Entity\AgendaItem;
use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Entity\AssemblyStatus;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\Attendee;
use App\Assembly\Entity\BoardMember;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Entity\Resolution;
use App\Assembly\Entity\ResolutionOutcome;
use App\Assembly\Presenter\AssemblyFormView;
use App\Assembly\Presenter\AssemblyListView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361 : les données du formulaire et de la liste sont des chaînes prêtes à remplir des champs (le gabarit échappe). */
final class AssemblyFormViewTest extends TestCase
{
    private function assembly(AssemblyStatus $status = AssemblyStatus::Brouillon, int $version = 1, ?int $previousId = null, int $id = 5): Assembly
    {
        $validated = $status === AssemblyStatus::Valide ? new \DateTimeImmutable('2026-10-12') : null;

        return new Assembly($id, AssemblyKind::Extraordinaire, new \DateTimeImmutable('2026-10-10'), null, '18:30', null, false, null, 'Rapport', null, $status, $version, $previousId, null, 3, new \DateTimeImmutable('2026-10-01'), $validated);
    }

    private function user(int $id, string $name, bool $active = true): User
    {
        return new User($id, "u{$id}@rehearsalbox.test", 'hash', $name, UserRole::Musicien, $active, 0, null);
    }

    #[Test]
    public function testAnEmptyDraftGivesEmptyStringsAndTheActiveAccounts(): void
    {
        $form = (new AssemblyFormView())->build($this->assembly(), AssemblyContent::empty(), [$this->user(7, 'Alice')], [], null);

        self::assertSame(5, $form['id']);
        self::assertSame(3, $form['revision']);
        self::assertTrue($form['isDraft']);
        self::assertSame('Assemblée générale extraordinaire du 10/10/2026', $form['title']);
        self::assertSame('Brouillon', $form['statusLabel']);
        self::assertSame('', $form['location']);
        self::assertSame('18:30', $form['openedAt']);
        self::assertSame('0', $form['quorum']);
        self::assertSame('', $form['moralReport']);
        self::assertSame('Rapport', $form['financialReport']);
        self::assertSame([['id' => '7', 'name' => 'Alice']], $form['users']);
        self::assertSame(['label' => 'Président', 'userId' => '', 'options' => [['id' => '7', 'name' => 'Alice']]], $form['board']['president']);
        self::assertSame([], $form['attendees']);
        self::assertNull($form['successorId']);
    }

    #[Test]
    public function testTheQuorumIsEmptyWhenNotEntered(): void
    {
        $assembly = new Assembly(5, AssemblyKind::Ordinaire, new \DateTimeImmutable('2026-10-10'), null, null, null, null, null, null, null, AssemblyStatus::Brouillon, 1, null, null, 0, new \DateTimeImmutable('2026-10-01'), null);

        self::assertSame('', (new AssemblyFormView())->build($assembly, AssemblyContent::empty(), [], [], null)['quorum']);
    }

    #[Test]
    public function testTheContentFillsTheRowsAndADeactivatedHolderStaysSelectable(): void
    {
        $content = new AssemblyContent(
            [BoardPost::President->value => new BoardMember(BoardPost::President, 9, 'Ancien titulaire')],
            [new Attendee(7, 'Alice', Attendance::Present, null), new Attendee(null, 'Invité', Attendance::Represente, 'Alice')],
            [new AgendaItem('Bilan', null)],
            [new Resolution('Approuver', 3, 1, 0, ResolutionOutcome::Adoptee), new Resolution('Refuser', 0, 0, 0, null)],
        );

        $form = (new AssemblyFormView())->build($this->assembly(), $content, [$this->user(7, 'Alice')], [], null);

        self::assertSame('9', $form['board']['president']['userId']);
        self::assertContains(['id' => '9', 'name' => 'Ancien titulaire'], $form['users']);
        self::assertSame(['userId' => '7', 'name' => '', 'attendance' => 'present', 'proxyHolder' => ''], $form['attendees'][0]);
        self::assertSame(['userId' => '', 'name' => 'Invité', 'attendance' => 'represente', 'proxyHolder' => 'Alice'], $form['attendees'][1]);
        self::assertSame([['title' => 'Bilan', 'discussion' => '']], $form['agenda']);
        self::assertSame(['text' => 'Approuver', 'for' => '3', 'against' => '1', 'abstain' => '0', 'outcome' => 'adoptee'], $form['resolutions'][0]);
        self::assertSame('', $form['resolutions'][1]['outcome']);
        self::assertSame(['present', 'excuse', 'represente'], $form['attendances']);
    }

    #[Test]
    public function testAValidatedAssemblyShowsItsDateAndItsSuccessor(): void
    {
        $form = (new AssemblyFormView())->build($this->assembly(AssemblyStatus::Valide), AssemblyContent::empty(), [], [], $this->assembly(AssemblyStatus::Brouillon, 2, 5, 6));

        self::assertFalse($form['isDraft']);
        self::assertSame('Validé le lundi 12 octobre 2026', $form['statusLabel']);
        self::assertSame(6, $form['successorId']);
    }

    #[Test]
    public function testEachPostOffersItsOwnCandidatesAndPreselectsASoleHolder(): void
    {
        $alice = $this->user(7, 'Alice');
        $bob = $this->user(8, 'Bob');
        $boardCandidates = ['president' => [$alice], 'tresorier' => [$alice, $bob]];

        $board = (new AssemblyFormView())->build($this->assembly(), AssemblyContent::empty(), [$alice, $bob], $boardCandidates, null)['board'];

        self::assertSame([['id' => '7', 'name' => 'Alice']], $board['president']['options']);
        self::assertSame('7', $board['president']['userId'], 'un seul titulaire possible : présélectionné');
        self::assertCount(2, $board['tresorier']['options']);
        self::assertSame('', $board['tresorier']['userId']);
        self::assertSame([['id' => '7', 'name' => 'Alice'], ['id' => '8', 'name' => 'Bob']], $board['secretaire']['options'], 'poste libre : tous les comptes actifs');
    }

    #[Test]
    public function testAStoredHolderStaysSelectableEvenWhenNoLongerOffered(): void
    {
        $content = new AssemblyContent([BoardPost::President->value => new BoardMember(BoardPost::President, 9, 'Ancien titulaire')], [], [], []);

        $board = (new AssemblyFormView())->build($this->assembly(), $content, [], ['president' => [$this->user(7, 'Alice')]], null)['board'];

        self::assertSame('9', $board['president']['userId']);
        self::assertContains(['id' => '9', 'name' => 'Ancien titulaire'], $board['president']['options']);
        self::assertContains(['id' => '7', 'name' => 'Alice'], $board['president']['options']);
    }

    #[Test]
    public function testTheListHasOneLinkedRowPerAssemblyWithItsStatus(): void
    {
        $rows = (new AssemblyListView())->rows([
            $this->assembly(AssemblyStatus::Brouillon, 1, null, 1),
            $this->assembly(AssemblyStatus::Brouillon, 3, 2, 2),
            $this->assembly(AssemblyStatus::Valide, 1, null, 3),
        ]);

        self::assertSame('/admin/assemblies/1', $rows[0]['url']);
        self::assertSame('Brouillon', $rows[0]['status']);
        self::assertSame('Correction de la version 2 en cours', $rows[1]['status']);
        self::assertSame(3, $rows[1]['version']);
        self::assertSame('Validé', $rows[2]['status']);
        self::assertSame('10/10/2026', $rows[2]['date']);
        self::assertSame('Assemblée générale extraordinaire', $rows[2]['title']);
        self::assertSame([], (new AssemblyListView())->rows([]));
    }
}
