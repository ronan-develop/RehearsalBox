<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Repository;

use App\Assembly\Entity\AgendaItem;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\Attendee;
use App\Assembly\Entity\BoardMember;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Entity\Resolution;
use App\Assembly\Entity\ResolutionOutcome;
use App\Assembly\Repository\MysqlAssemblyContentRepository;
use App\Tests\Database\RepositoryTestCase;
use App\Tests\Scenarios\MessagingScenario;
use PHPUnit\Framework\Attributes\Test;

/** #361 : tables filles d'un compte rendu d'AG (bureau, présences, ordre du jour, résolutions). */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class MysqlAssemblyContentRepositoryTest extends RepositoryTestCase
{
    use MessagingScenario;

    private MysqlAssemblyContentRepository $contents;
    private int $alice;
    private int $bob;
    private int $carole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->contents = new MysqlAssemblyContentRepository($this->pdo);
        $this->alice = $this->user('Alice')->id();
        $this->bob = $this->user('Bob')->id();
        $this->carole = $this->user('Carole')->id();
    }

    /** Insère un compte rendu parent et retourne son identifiant. */
    private function assembly(int $version = 1, ?int $previousId = null): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO assemblies (kind, held_on, version, previous_id, created_by_user_id, created_at, updated_at)
             VALUES (:kind, :held_on, :version, :previous_id, :user_id, :created_at, :updated_at)'
        );
        $statement->execute([
            'kind' => 'ordinaire',
            'held_on' => '2026-10-04',
            'version' => $version,
            'previous_id' => $previousId,
            'user_id' => $this->alice,
            'created_at' => $this->now->format('Y-m-d H:i:s'),
            'updated_at' => $this->now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function fullContent(): AssemblyContent
    {
        return new AssemblyContent(
            [
                'president' => new BoardMember(BoardPost::President, $this->alice, 'Alice'),
                'tresorier' => new BoardMember(BoardPost::Tresorier, $this->bob, 'Bob'),
                'secretaire' => new BoardMember(BoardPost::Secretaire, $this->carole, 'Carole'),
            ],
            [
                new Attendee($this->alice, 'Alice', Attendance::Present, null),
                new Attendee(null, 'Jean-Luc', Attendance::Excuse, null),
                new Attendee($this->bob, 'Bob', Attendance::Represente, 'Carole'),
            ],
            [
                new AgendaItem('Bilan moral', "On a dit « beaucoup » de choses & <b>vite</b>"),
                new AgendaItem('Budget', null),
            ],
            [
                new Resolution("Achat d'un ampli", 3, 1, 0, ResolutionOutcome::Adoptee),
                new Resolution('Hausse du tarif', 1, 3, 1, ResolutionOutcome::Rejetee),
                new Resolution('Report du vote', 0, 0, 0, null),
            ],
        );
    }

    #[Test]
    public function testReplaceThenLoadGivesBackTheSameFourTables(): void
    {
        $id = $this->assembly();
        $content = $this->fullContent();

        $this->contents->replace($id, $content);

        self::assertEquals($content, $this->contents->load($id));
    }

    #[Test]
    public function testASecondReplaceReplacesInsteadOfAdding(): void
    {
        $id = $this->assembly();
        $this->contents->replace($id, $this->fullContent());
        $smaller = new AssemblyContent(
            [],
            [new Attendee($this->alice, 'Alice', Attendance::Present, null)],
            [new AgendaItem('Seul point', null)],
            [],
        );

        $this->contents->replace($id, $smaller);

        self::assertEquals($smaller, $this->contents->load($id));
    }

    #[Test]
    public function testCopyGivesTheSameContentOnAnotherVersionAndKeepsTheSource(): void
    {
        $first = $this->assembly();
        $second = $this->assembly(2, $first);
        $content = $this->fullContent();
        $this->contents->replace($first, $content);

        $this->contents->copy($first, $second);

        self::assertEquals($content, $this->contents->load($second));
        self::assertEquals($content, $this->contents->load($first), 'la source reste intacte');
    }

    #[Test]
    public function testANonRegisteredAttendeeHasNoUserIdAfterALoad(): void
    {
        $id = $this->assembly();
        $this->contents->replace($id, new AssemblyContent([], [new Attendee(null, 'Jean-Luc', Attendance::Present, null)], [], []));

        $loaded = $this->contents->load($id)->attendees;

        self::assertCount(1, $loaded);
        self::assertNull($loaded[0]->userId);
        self::assertSame('Jean-Luc', $loaded[0]->name);
    }

    #[Test]
    public function testAnEmptyContentLoadsAsEmptyLists(): void
    {
        $id = $this->assembly();
        self::assertEquals(AssemblyContent::empty(), $this->contents->load($id));

        $this->contents->replace($id, $this->fullContent());
        $this->contents->replace($id, AssemblyContent::empty());

        self::assertEquals(AssemblyContent::empty(), $this->contents->load($id));
    }

    #[Test]
    public function testTextsWithQuotesAndMarkupAreStoredAndReadBackAsIs(): void
    {
        $id = $this->assembly();
        $text = "L'achat « neuf » \"à crédit\" <script>alert('x')</script>";
        $content = new AssemblyContent(
            ['president' => new BoardMember(BoardPost::President, $this->alice, "Alice d'<i>Ici</i>")],
            [new Attendee(null, "O'Neil \"Bob\" <script>", Attendance::Represente, "Dupont <b>&</b>")],
            [new AgendaItem($text, $text)],
            [new Resolution($text, 1, 0, 0, ResolutionOutcome::Adoptee)],
        );

        $this->contents->replace($id, $content);

        self::assertEquals($content, $this->contents->load($id));
    }

    #[Test]
    public function testTheOrderOfAttendeesAndAgendaIsKept(): void
    {
        $id = $this->assembly();
        $titles = ['Zeta', 'Alpha', 'Milieu'];
        $agenda = array_map(static fn (string $title): AgendaItem => new AgendaItem($title, null), $titles);
        $attendees = [
            new Attendee($this->carole, 'Carole', Attendance::Present, null),
            new Attendee($this->alice, 'Alice', Attendance::Present, null),
            new Attendee(null, 'Anonyme', Attendance::Excuse, null),
        ];

        $this->contents->replace($id, new AssemblyContent([], $attendees, $agenda, []));
        $loaded = $this->contents->load($id);

        self::assertSame($titles, array_map(static fn (AgendaItem $item): string => $item->title, $loaded->agenda));
        self::assertEquals($attendees, $loaded->attendees);
    }
}
