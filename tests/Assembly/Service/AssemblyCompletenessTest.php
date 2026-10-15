<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Service;

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
use App\Assembly\Service\AssemblyCompleteness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #361 : un compte rendu n'est validable que si les champs exigés sont tous renseignés. */
final class AssemblyCompletenessTest extends TestCase
{
    #[Test]
    public function testACompleteReportHasNoMissingField(): void
    {
        self::assertSame([], (new AssemblyCompleteness())->missing(self::assembly(), self::content()));
    }

    #[Test]
    public function testMissingLocationIsReported(): void
    {
        self::assertArrayHasKey('location', (new AssemblyCompleteness())->missing(self::assembly(location: null), self::content()));
    }

    #[Test]
    public function testMissingOpeningTimeIsReported(): void
    {
        self::assertArrayHasKey('openedAt', (new AssemblyCompleteness())->missing(self::assembly(openedAt: null), self::content()));
    }

    #[Test]
    public function testMissingClosingTimeIsReported(): void
    {
        self::assertArrayHasKey('closedAt', (new AssemblyCompleteness())->missing(self::assembly(closedAt: null), self::content()));
    }

    #[Test]
    public function testMissingQuorumAnswerIsReported(): void
    {
        self::assertArrayHasKey('quorumReached', (new AssemblyCompleteness())->missing(self::assembly(quorumReached: null), self::content()));
    }

    #[Test]
    public function testMissingPresidentIsReported(): void
    {
        $content = self::content(board: [
            BoardPost::Tresorier->value => self::boardMember(BoardPost::Tresorier),
            BoardPost::Secretaire->value => self::boardMember(BoardPost::Secretaire),
        ]);

        self::assertArrayHasKey('board.president', (new AssemblyCompleteness())->missing(self::assembly(), $content));
    }

    #[Test]
    public function testMissingTreasurerIsReported(): void
    {
        $content = self::content(board: [
            BoardPost::President->value => self::boardMember(BoardPost::President),
            BoardPost::Secretaire->value => self::boardMember(BoardPost::Secretaire),
        ]);

        self::assertArrayHasKey('board.tresorier', (new AssemblyCompleteness())->missing(self::assembly(), $content));
    }

    #[Test]
    public function testMissingSecretaryIsReported(): void
    {
        $content = self::content(board: [
            BoardPost::President->value => self::boardMember(BoardPost::President),
            BoardPost::Tresorier->value => self::boardMember(BoardPost::Tresorier),
        ]);

        self::assertArrayHasKey('board.secretaire', (new AssemblyCompleteness())->missing(self::assembly(), $content));
    }

    #[Test]
    public function testNoAttendeeIsReported(): void
    {
        self::assertArrayHasKey('attendees', (new AssemblyCompleteness())->missing(self::assembly(), self::content(attendees: [])));
    }

    #[Test]
    public function testNoAgendaItemIsReported(): void
    {
        self::assertArrayHasKey('agenda', (new AssemblyCompleteness())->missing(self::assembly(), self::content(agenda: [])));
    }

    #[Test]
    public function testNoResolutionIsValid(): void
    {
        self::assertSame([], (new AssemblyCompleteness())->missing(self::assembly(), self::content(resolutions: [])));
    }

    #[Test]
    public function testQuorumNotReachedIsValid(): void
    {
        self::assertSame([], (new AssemblyCompleteness())->missing(self::assembly(quorumReached: false), self::content()));
    }

    #[Test]
    public function testResolutionWithoutOutcomeIsReportedByIndex(): void
    {
        $content = self::content(resolutions: [
            new Resolution(text: 'Budget', for: 5, against: 0, abstain: 1, outcome: ResolutionOutcome::Adoptee),
            new Resolution(text: 'Cotisation', for: 3, against: 2, abstain: 0, outcome: null),
        ]);

        self::assertSame(['resolutions.1.outcome'], array_keys((new AssemblyCompleteness())->missing(self::assembly(), $content)));
    }

    #[Test]
    public function testSeveralMissingFieldsAreAllReported(): void
    {
        $missing = (new AssemblyCompleteness())->missing(
            self::assembly(location: null, closedAt: null, quorumReached: null),
            self::content(attendees: [], agenda: []),
        );

        self::assertSame(
            ['location', 'closedAt', 'quorumReached', 'attendees', 'agenda'],
            array_keys($missing),
        );
    }

    private static function assembly(
        ?string $location = 'Local de la rue des Lilas',
        ?string $openedAt = '20:00',
        ?string $closedAt = '21:30',
        ?bool $quorumReached = true,
    ): Assembly {
        return new Assembly(
            id: 1,
            kind: AssemblyKind::Ordinaire,
            heldOn: new \DateTimeImmutable('2026-10-01'),
            location: $location,
            openedAt: $openedAt,
            closedAt: $closedAt,
            quorumReached: $quorumReached,
            moralReport: null,
            financialReport: null,
            otherBusiness: null,
            status: AssemblyStatus::Brouillon,
            version: 1,
            previousId: null,
            correctionReason: null,
            revision: 1,
            createdAt: new \DateTimeImmutable('2026-10-01 22:00'),
            validatedAt: null,
        );
    }

    /**
     * @param array<string, BoardMember>|null $board
     * @param list<Attendee>|null $attendees
     * @param list<AgendaItem>|null $agenda
     * @param list<Resolution>|null $resolutions
     */
    private static function content(
        ?array $board = null,
        ?array $attendees = null,
        ?array $agenda = null,
        ?array $resolutions = null,
    ): AssemblyContent {
        return new AssemblyContent(
            board: $board ?? [
                BoardPost::President->value => self::boardMember(BoardPost::President),
                BoardPost::Tresorier->value => self::boardMember(BoardPost::Tresorier),
                BoardPost::Secretaire->value => self::boardMember(BoardPost::Secretaire),
            ],
            attendees: $attendees ?? [new Attendee(userId: 1, name: 'Ronan', attendance: Attendance::Present, proxyHolder: null)],
            agenda: $agenda ?? [new AgendaItem(title: 'Bilan 2026', discussion: 'Échanges sur la saison.')],
            resolutions: $resolutions ?? [new Resolution(text: 'Budget', for: 5, against: 0, abstain: 1, outcome: ResolutionOutcome::Adoptee)],
        );
    }

    private static function boardMember(BoardPost $post): BoardMember
    {
        return new BoardMember(post: $post, userId: 1, name: 'Ronan');
    }
}
