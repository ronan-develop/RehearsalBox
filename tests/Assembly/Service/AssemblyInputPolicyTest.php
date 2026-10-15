<?php

declare(strict_types=1);

namespace App\Tests\Assembly\Service;

use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\ResolutionOutcome;
use App\Assembly\Exception\AssemblyValidationException;
use App\Assembly\Service\AssemblyDraft;
use App\Assembly\Service\AssemblyInputPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssemblyInputPolicyTest extends TestCase
{
    #[Test]
    public function testANominalDocumentIsParsedIntoADraft(): void
    {
        $draft = (new AssemblyInputPolicy())->parse(self::nominal());

        self::assertSame(AssemblyKind::Ordinaire, $draft->kind);
        self::assertSame('2026-03-14', $draft->heldOn->format('Y-m-d'));
        self::assertSame('Local de la rue Pasteur', $draft->location);
        self::assertSame('19:00', $draft->openedAt);
        self::assertSame('21:30', $draft->closedAt);
        self::assertTrue($draft->quorumReached);
        self::assertNull($draft->otherBusiness);
        self::assertSame(['president' => 1, 'tresorier' => 2, 'secretaire' => 3], $draft->boardUserIds);
        self::assertSame(
            [
                ['userId' => 1, 'name' => null, 'attendance' => Attendance::Present, 'proxyHolder' => null],
                ['userId' => null, 'name' => 'Marie Dupont', 'attendance' => Attendance::Excuse, 'proxyHolder' => null],
                ['userId' => null, 'name' => 'Paul', 'attendance' => Attendance::Represente, 'proxyHolder' => 'Jean'],
            ],
            $draft->attendees,
        );
        self::assertCount(1, $draft->agenda);
        self::assertSame('Débat' . "\n" . 'et' . "\t" . 'plus', $draft->agenda[0]->discussion);
        self::assertSame('Achat de matériel', $draft->resolutions[0]->text);
        self::assertSame(5, $draft->resolutions[0]->for);
        self::assertSame(0, $draft->resolutions[0]->against);
        self::assertSame(1, $draft->resolutions[0]->abstain);
        self::assertSame(ResolutionOutcome::Adoptee, $draft->resolutions[0]->outcome);
    }

    #[Test]
    public function testAMinimalDraftIsAccepted(): void
    {
        $draft = (new AssemblyInputPolicy())->parse(['kind' => 'extraordinaire', 'heldOn' => '2026-03-14']);

        self::assertInstanceOf(AssemblyDraft::class, $draft);
        self::assertSame(AssemblyKind::Extraordinaire, $draft->kind);
        self::assertNull($draft->location);
        self::assertNull($draft->quorumReached);
        self::assertSame([], $draft->boardUserIds);
        self::assertSame([], $draft->attendees);
        self::assertSame([], $draft->agenda);
        self::assertSame([], $draft->resolutions);
    }

    #[Test]
    public function testBlankStringsBecomeNullAndScriptTextIsKeptAsIs(): void
    {
        $input = self::nominal();
        $input['location'] = '   ';
        $input['moralReport'] = '<script>alert(1)</script>';
        $draft = (new AssemblyInputPolicy())->parse($input);

        self::assertNull($draft->location);
        self::assertSame('<script>alert(1)</script>', $draft->moralReport);
    }

    #[Test]
    public function testSeveralErrorsAreCollectedInOneException(): void
    {
        $input = self::nominal();
        $input['heldOn'] = '2026-02-30';
        $input['openedAt'] = '25:00';
        $input['attendees'][0]['name'] = 'Marie';
        $input['resolutions'][0]['text'] = '   ';

        $fields = self::refusalFields($input);

        self::assertSame(['heldOn', 'openedAt', 'attendees.0.name', 'resolutions.0.text'], array_keys($fields));
        foreach ($fields as $message) {
            self::assertNotSame('', $message);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function refusals(): iterable
    {
        yield 'kind inconnu' => [self::with(['kind' => 'speciale']), 'kind'];
        yield 'date impossible' => [self::with(['heldOn' => '2026-02-30']), 'heldOn'];
        yield 'date au mauvais format' => [self::with(['heldOn' => '14/03/2026']), 'heldOn'];
        yield 'heure invalide' => [self::with(['openedAt' => '25:00']), 'openedAt'];
        yield 'heure sans zéro en tête' => [self::with(['openedAt' => '9:00']), 'openedAt'];
        yield 'ouverture égale à la clôture' => [self::with(['openedAt' => '19:00', 'closedAt' => '19:00']), 'closedAt'];
        yield 'ouverture après la clôture' => [self::with(['openedAt' => '22:00', 'closedAt' => '21:30']), 'closedAt'];
        yield 'lieu trop long' => [self::with(['location' => str_repeat('a', 201)]), 'location'];
        yield 'titre de point trop long' => [self::withAgenda([['title' => str_repeat('t', 201), 'discussion' => null]]), 'agenda.0.title'];
        yield 'rapport moral en tableau' => [self::with(['moralReport' => ['x']]), 'moralReport'];
        yield 'rapport trop long' => [self::with(['financialReport' => str_repeat('r', 20001)]), 'financialReport'];
        yield 'nom en nombre' => [self::withAttendees([['userId' => null, 'name' => 42, 'attendance' => 'present', 'proxyHolder' => null]]), 'attendees.0.name'];
        yield 'nom avec caractère de contrôle' => [self::withAttendees([['userId' => null, 'name' => "Pa\x00ul", 'attendance' => 'present', 'proxyHolder' => null]]), 'attendees.0.name'];
        yield 'trop de présences' => [self::with(['attendees' => self::manyAttendees(201)]), 'attendees'];
        yield 'trop de points' => [self::with(['agenda' => self::manyAgenda(31)]), 'agenda'];
        yield 'trop de résolutions' => [self::with(['resolutions' => self::manyResolutions(31)]), 'resolutions'];
        yield 'vote négatif' => [self::withResolution(['for' => -1]), 'resolutions.0.for'];
        yield 'vote au-dessus de 9999' => [self::withResolution(['against' => 10000]), 'resolutions.0.against'];
        yield 'vote non numérique' => [self::withResolution(['abstain' => 'deux']), 'resolutions.0.abstain'];
        yield 'vote décimal' => [self::withResolution(['for' => 1.5]), 'resolutions.0.for'];
        yield 'résultat inconnu' => [self::withResolution(['outcome' => 'reportee']), 'resolutions.0.outcome'];
        yield 'texte de résolution vide' => [self::withResolution(['text' => '   ']), 'resolutions.0.text'];
        yield 'titre de point vide' => [self::withAgenda([['title' => '  ', 'discussion' => null]]), 'agenda.0.title'];
        yield 'bureau : même compte deux postes' => [self::with(['board' => ['president' => 1, 'tresorier' => 1]]), 'board.tresorier'];
        yield 'bureau : identifiant zéro' => [self::with(['board' => ['president' => 0]]), 'board.president'];
        yield 'bureau : identifiant décimal' => [self::with(['board' => ['president' => '1.5']]), 'board.president'];
        yield 'présence avec userId ET name' => [self::withAttendees([['userId' => 4, 'name' => 'Paul', 'attendance' => 'present', 'proxyHolder' => null]]), 'attendees.0.name'];
        yield 'présence ni userId ni name' => [self::withAttendees([['userId' => null, 'name' => null, 'attendance' => 'present', 'proxyHolder' => null]]), 'attendees.0.name'];
        yield 'pouvoir manquant' => [self::withAttendees([['userId' => null, 'name' => 'Paul', 'attendance' => 'represente', 'proxyHolder' => null]]), 'attendees.0.proxyHolder'];
        yield 'pouvoir sur une présence non représentée' => [self::withAttendees([['userId' => null, 'name' => 'Paul', 'attendance' => 'present', 'proxyHolder' => 'Jean']]), 'attendees.0.proxyHolder'];
        yield 'userId en double' => [self::withAttendees([
            ['userId' => 1, 'name' => null, 'attendance' => 'present', 'proxyHolder' => null],
            ['userId' => 1, 'name' => null, 'attendance' => 'excuse', 'proxyHolder' => null],
        ]), 'attendees.1.userId'];
        yield 'présence de statut inconnu' => [self::withAttendees([['userId' => null, 'name' => 'Paul', 'attendance' => 'absent', 'proxyHolder' => null]]), 'attendees.0.attendance'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function testAnInvalidDocumentIsRefusedOnItsField(array $input, string $field): void
    {
        self::assertArrayHasKey($field, self::refusalFields($input));
    }

    /** @return array<string, string> */
    private static function refusalFields(array $input): array
    {
        try {
            (new AssemblyInputPolicy())->parse($input);
        } catch (AssemblyValidationException $exception) {
            return $exception->fields();
        }
        self::fail('Une saisie invalide doit être refusée.');
    }

    /** @return array<string, mixed> */
    private static function nominal(): array
    {
        return [
            'kind' => 'ordinaire',
            'heldOn' => '2026-03-14',
            'location' => 'Local de la rue Pasteur',
            'openedAt' => '19:00',
            'closedAt' => '21:30',
            'quorumReached' => true,
            'moralReport' => 'Rapport moral.',
            'financialReport' => 'Bilan.',
            'otherBusiness' => null,
            'board' => ['president' => 1, 'tresorier' => 2, 'secretaire' => 3],
            'attendees' => [
                ['userId' => 1, 'name' => null, 'attendance' => 'present', 'proxyHolder' => null],
                ['userId' => null, 'name' => 'Marie Dupont', 'attendance' => 'excuse', 'proxyHolder' => null],
                ['userId' => null, 'name' => 'Paul', 'attendance' => 'represente', 'proxyHolder' => 'Jean'],
            ],
            'agenda' => [['title' => 'Bilan', 'discussion' => "Débat\net\tplus"]],
            'resolutions' => [['text' => 'Achat de matériel', 'for' => '5', 'against' => 0, 'abstain' => 1, 'outcome' => 'adoptee']],
        ];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private static function with(array $changes): array
    {
        return array_merge(self::nominal(), $changes);
    }

    /** @param list<array<string, mixed>> $attendees */
    private static function withAttendees(array $attendees): array
    {
        return self::with(['attendees' => $attendees]);
    }

    /** @param list<array<string, mixed>> $agenda */
    private static function withAgenda(array $agenda): array
    {
        return self::with(['agenda' => $agenda]);
    }

    /** @param array<string, mixed> $changes champs de la première résolution */
    private static function withResolution(array $changes): array
    {
        $resolution = array_merge(self::nominal()['resolutions'][0], $changes);

        return self::with(['resolutions' => [$resolution]]);
    }

    /** @return list<array{userId: ?int, name: string, attendance: string, proxyHolder: ?string}> */
    private static function manyAttendees(int $count): array
    {
        $attendees = [];
        for ($i = 0; $i < $count; ++$i) {
            $attendees[] = ['userId' => null, 'name' => 'Personne ' . $i, 'attendance' => 'present', 'proxyHolder' => null];
        }

        return $attendees;
    }

    /** @return list<array{title: string, discussion: null}> */
    private static function manyAgenda(int $count): array
    {
        return array_fill(0, $count, ['title' => 'Point', 'discussion' => null]);
    }

    /** @return list<array{text: string, for: int, against: int, abstain: int, outcome: null}> */
    private static function manyResolutions(int $count): array
    {
        return array_fill(0, $count, ['text' => 'Résolution', 'for' => 1, 'against' => 0, 'abstain' => 0, 'outcome' => null]);
    }
}
