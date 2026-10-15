<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Presenter;

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
use App\Assembly\Presenter\AssemblyMinutesView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361 : le compte rendu imprimable se compose de phrases types ; aucun texte n'est inventé, les rapports sont recopiés bruts. */
final class AssemblyMinutesViewTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function assembly(array $overrides = []): Assembly
    {
        return new Assembly(...$overrides + [
            'id' => 1,
            'kind' => AssemblyKind::Ordinaire,
            'heldOn' => new \DateTimeImmutable('2026-10-10'),
            'location' => 'Salle des fêtes',
            'openedAt' => '18:30',
            'closedAt' => '20:15',
            'quorumReached' => true,
            'moralReport' => null,
            'financialReport' => null,
            'otherBusiness' => null,
            'status' => AssemblyStatus::Brouillon,
            'version' => 1,
            'previousId' => null,
            'correctionReason' => null,
            'revision' => 1,
            'createdAt' => new \DateTimeImmutable('2026-10-01'),
            'validatedAt' => null,
        ]);
    }

    /**
     * @param list<Attendee> $attendees
     * @param list<AgendaItem> $agenda
     * @param list<Resolution> $resolutions
     * @param array<string, string> $board poste (valeur de BoardPost) => nom
     */
    private function content(array $attendees = [], array $agenda = [], array $resolutions = [], array $board = []): AssemblyContent
    {
        $members = [];
        foreach ($board as $post => $name) {
            $members[$post] = new BoardMember(BoardPost::from($post), 1, $name);
        }

        return new AssemblyContent($members, $attendees, $agenda, $resolutions);
    }

    /** @param array<string, mixed> $overrides */
    private function view(?Assembly $assembly = null, ?AssemblyContent $content = null, ?Assembly $successor = null, array $overrides = []): array
    {
        $assembly ??= $this->assembly($overrides);

        return (new AssemblyMinutesView())->build($assembly, $content ?? AssemblyContent::empty(), 'Les Rockeurs', $successor);
    }

    private function attendee(string $name, Attendance $attendance, ?string $holder = null): Attendee
    {
        return new Attendee(null, $name, $attendance, $holder);
    }

    #[Test]
    public function testTheTitleFollowsTheKindOfAssembly(): void
    {
        self::assertSame("Compte rendu de l'assemblée générale ordinaire", $this->view()['title']);
        self::assertSame("Compte rendu de l'assemblée générale extraordinaire", $this->view(overrides: ['kind' => AssemblyKind::Extraordinaire])['title']);
        self::assertSame('Les Rockeurs', $this->view()['associationName']);
    }

    #[Test]
    public function testADraftShowsTheBannerAndNoVersionLine(): void
    {
        $view = $this->view();

        self::assertSame('BROUILLON — document non validé', $view['statusBanner']);
        self::assertNull($view['versionLine']);
    }

    #[Test]
    public function testAValidatedVersionOneIsNotReplacedAndHasNoBanner(): void
    {
        $view = $this->view(overrides: ['status' => AssemblyStatus::Valide, 'validatedAt' => new \DateTimeImmutable('2026-10-12')]);

        self::assertNull($view['statusBanner']);
        self::assertSame('Version 1, validée le lundi 12 octobre 2026.', $view['versionLine']);
    }

    #[Test]
    public function testAValidatedVersionReplacedByASuccessorShowsTheBanner(): void
    {
        $successor = $this->assembly(['version' => 2, 'status' => AssemblyStatus::Valide, 'previousId' => 1]);
        $view = $this->view(overrides: ['status' => AssemblyStatus::Valide, 'validatedAt' => new \DateTimeImmutable('2026-10-12')], successor: $successor);

        self::assertSame('Version remplacée par la version 2', $view['statusBanner']);
    }

    #[Test]
    public function testVersionTwoExplainsTheCorrectionReason(): void
    {
        $view = $this->view(overrides: [
            'status' => AssemblyStatus::Valide,
            'version' => 2,
            'previousId' => 1,
            'correctionReason' => 'erreur de décompte',
            'validatedAt' => new \DateTimeImmutable('2026-10-14'),
        ]);

        self::assertSame('Version 2, validée le mercredi 14 octobre 2026, remplace la version 1. Motif : erreur de décompte.', $view['versionLine']);
        self::assertNull($view['statusBanner']);
    }

    /** @return array<string, array{?bool, ?string}> */
    public static function quorums(): array
    {
        return [
            'atteint' => [true, 'Le quorum est atteint.'],
            'non atteint' => [false, "Le quorum n'est pas atteint."],
            'non renseigné' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('quorums')]
    public function testTheQuorumSentence(?bool $reached, ?string $expected): void
    {
        self::assertSame($expected, $this->view(overrides: ['quorumReached' => $reached])['quorum']);
    }

    #[Test]
    public function testTheOpeningSentenceWithPlaceAndOpeningTime(): void
    {
        self::assertSame(
            "L'assemblée générale ordinaire s'est tenue le samedi 10 octobre 2026 à Salle des fêtes. La séance est ouverte à 18 h 30.",
            $this->view()['opening'],
        );
    }

    #[Test]
    public function testTheOpeningSentenceWithoutPlaceNorOpeningTime(): void
    {
        $view = $this->view(overrides: ['kind' => AssemblyKind::Extraordinaire, 'location' => null, 'openedAt' => null]);

        self::assertSame("L'assemblée générale extraordinaire s'est tenue le samedi 10 octobre 2026.", $view['opening']);
    }

    #[Test]
    public function testAnHourUnderTenIsWrittenWithoutLeadingZero(): void
    {
        $view = $this->view(overrides: ['openedAt' => '09:05', 'closedAt' => '20:00']);

        self::assertStringEndsWith('La séance est ouverte à 9 h 05.', $view['opening']);
    }

    #[Test]
    public function testTheClosingSentenceWithAndWithoutTheClosingTime(): void
    {
        self::assertSame(
            "L'ordre du jour étant épuisé, la séance est levée à 20 h 15.",
            $this->view()['closing'],
        );
        self::assertSame(
            "L'ordre du jour étant épuisé, la séance est levée.",
            $this->view(overrides: ['closedAt' => null])['closing'],
        );
    }

    #[Test]
    public function testTheBoardKeepsOnlyPresentPostsInOrder(): void
    {
        $content = $this->content(board: [
            'secretaire' => 'Claire Roux',
            'president' => 'Paul Dubois',
        ]);

        self::assertSame(
            [
                ['label' => 'Président', 'name' => 'Paul Dubois'],
                ['label' => 'Secrétaire', 'name' => 'Claire Roux'],
            ],
            $this->view(content: $content)['board'],
        );
    }

    #[Test]
    public function testTheSignaturesAreThePresidentThenTheSecretaryWithEmptyNameWhenAbsent(): void
    {
        $content = $this->content(board: ['president' => 'Paul Dubois']);

        self::assertSame(
            [
                ['label' => 'Président', 'name' => 'Paul Dubois'],
                ['label' => 'Secrétaire', 'name' => ''],
            ],
            $this->view(content: $content)['signatures'],
        );
    }

    /** @return array<string, array{int, int, int, string}> */
    public static function counts(): array
    {
        return [
            'pluriels' => [12, 3, 2, '12 présents, 3 représentés, 2 excusés.'],
            'singuliers' => [1, 1, 1, '1 présent, 1 représenté, 1 excusé.'],
            'zéros au singulier' => [0, 0, 0, '0 présent, 0 représenté, 0 excusé.'],
        ];
    }

    #[Test]
    #[DataProvider('counts')]
    public function testTheCountUsesSingularAndPluralForms(int $present, int $represented, int $excused, string $expected): void
    {
        $attendees = [
            ...array_fill(0, $present, $this->attendee('Présent', Attendance::Present)),
            ...array_fill(0, $represented, $this->attendee('Représenté', Attendance::Represente, 'Porteur')),
            ...array_fill(0, $excused, $this->attendee('Excusé', Attendance::Excuse)),
        ];

        self::assertSame($expected, $this->view(content: $this->content(attendees: $attendees))['counts']);
    }

    #[Test]
    public function testTheNamesAreListedByAttendance(): void
    {
        $attendees = [
            $this->attendee('Paul Dubois', Attendance::Present),
            $this->attendee('Léa Martin', Attendance::Represente, 'Paul Dubois'),
            $this->attendee('Claire Roux', Attendance::Excuse),
        ];
        $view = $this->view(content: $this->content(attendees: $attendees));

        self::assertSame(['Paul Dubois'], $view['present']);
        self::assertSame(['Léa Martin (pouvoir à Paul Dubois)'], $view['represented']);
        self::assertSame(['Claire Roux'], $view['excused']);
    }

    #[Test]
    public function testTheAgendaIsNumberedFromOne(): void
    {
        $agenda = [new AgendaItem('Rapport moral', 'Discussion courte.'), new AgendaItem('Divers', null)];

        self::assertSame(
            [
                ['number' => 1, 'title' => 'Rapport moral', 'discussion' => 'Discussion courte.'],
                ['number' => 2, 'title' => 'Divers', 'discussion' => null],
            ],
            $this->view(content: $this->content(agenda: $agenda))['agenda'],
        );
    }

    #[Test]
    public function testAResolutionGivesItsVotesAndOutcome(): void
    {
        $resolutions = [
            new Resolution('Approuver les comptes.', 10, 1, 2, ResolutionOutcome::Adoptee),
            new Resolution('Changer de local.', 3, 8, 0, ResolutionOutcome::Rejetee),
            new Resolution('Question ouverte.', 0, 0, 0, null),
        ];
        $view = $this->view(content: $this->content(resolutions: $resolutions));

        self::assertSame(
            [
                ['number' => 1, 'text' => 'Approuver les comptes.', 'votes' => 'Pour : 10 — Contre : 1 — Abstentions : 2', 'outcome' => 'La résolution est adoptée.'],
                ['number' => 2, 'text' => 'Changer de local.', 'votes' => 'Pour : 3 — Contre : 8 — Abstentions : 0', 'outcome' => 'La résolution est rejetée.'],
                ['number' => 3, 'text' => 'Question ouverte.', 'votes' => 'Pour : 0 — Contre : 0 — Abstentions : 0', 'outcome' => null],
            ],
            $view['resolutions'],
        );
        self::assertNull($view['noResolution']);
    }

    #[Test]
    public function testWithoutResolutionTheSentenceSaysNoneWasSubmitted(): void
    {
        $view = $this->view();

        self::assertSame([], $view['resolutions']);
        self::assertSame("Aucune résolution n'a été soumise au vote.", $view['noResolution']);
    }

    #[Test]
    public function testTheReportsAreKeptRawAndNotEscaped(): void
    {
        $view = $this->view(overrides: [
            'moralReport' => 'Bilan <b>positif</b> & calme',
            'financialReport' => null,
            'otherBusiness' => 'Rien à signaler',
        ]);

        self::assertSame('Bilan <b>positif</b> & calme', $view['moralReport']);
        self::assertNull($view['financialReport']);
        self::assertSame('Rien à signaler', $view['otherBusiness']);
    }

    #[Test]
    public function testTheShapeHasEveryKey(): void
    {
        $view = $this->view();

        self::assertSame(['title', 'associationName', 'statusBanner', 'versionLine', 'opening', 'quorum', 'board', 'counts', 'present', 'represented', 'excused', 'agenda', 'resolutions', 'noResolution', 'moralReport', 'financialReport', 'otherBusiness', 'closing', 'signatures'], array_keys($view));
    }
}
