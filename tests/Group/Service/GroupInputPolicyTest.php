<?php

declare(strict_types=1);

namespace App\Tests\Group\Service;

use App\Group\Entity\LineupMember;
use App\Group\Entity\UpcomingShow;
use App\Group\Service\GroupInputPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupInputPolicyTest extends TestCase
{
    #[Test]
    public function testAValidGroupHasNoViolation(): void
    {
        self::assertSame([], (new GroupInputPolicy())->groupViolations('Black Sabbath Tribute', 'métal', '#e63946', 'contact@example.test'));
        self::assertSame([], (new GroupInputPolicy())->groupViolations('Solo', null, null, 'contact@example.test'), 'genre et couleur facultatifs');
    }

    /** @return iterable<string, array{string, ?string, ?string, string, string}> */
    public static function invalidGroups(): iterable
    {
        yield 'nom vide' => ['', null, null, 'a@example.test', 'name'];
        yield 'nom d\'espaces' => ['   ', null, null, 'a@example.test', 'name'];
        yield 'nom trop long' => [str_repeat('a', 121), null, null, 'a@example.test', 'name'];
        yield 'nom avec caractère de contrôle' => ["Nom\x00piégé", null, null, 'a@example.test', 'name'];
        yield 'genre trop long' => ['Nom', str_repeat('g', 61), null, 'a@example.test', 'genre'];
        yield 'couleur sans dièse' => ['Nom', null, 'e63946', 'a@example.test', 'colorHex'];
        yield 'couleur trop courte' => ['Nom', null, '#e63', 'a@example.test', 'colorHex'];
        yield 'couleur avec du CSS' => ['Nom', null, '#e63946;background:url(x)', 'a@example.test', 'colorHex'];
        yield 'couleur non hexadécimale' => ['Nom', null, '#gggggg', 'a@example.test', 'colorHex'];
        yield 'e-mail absent' => ['Nom', null, null, '', 'contactEmail'];
        yield 'e-mail invalide' => ['Nom', null, null, 'pas-un-email', 'contactEmail'];
        yield 'e-mail trop long' => ['Nom', null, null, str_repeat('a', 185) . '@example.test', 'contactEmail'];
    }

    #[Test]
    #[DataProvider('invalidGroups')]
    public function testEachInvalidFieldIsReportedUnderItsOwnName(string $name, ?string $genre, ?string $color, string $email, string $field): void
    {
        $violations = (new GroupInputPolicy())->groupViolations($name, $genre, $color, $email);

        self::assertArrayHasKey($field, $violations);
        self::assertNotSame('', $violations[$field]);
    }

    #[Test]
    public function testSeveralProblemsAreReportedAtOnce(): void
    {
        $violations = (new GroupInputPolicy())->groupViolations('', null, 'rouge', 'x');

        self::assertSame(['name', 'colorHex', 'contactEmail'], array_keys($violations));
    }

    #[Test]
    public function testTheNameIsNormalizedBeforeBeingStored(): void
    {
        $policy = new GroupInputPolicy();

        self::assertSame('Nebula Sprawl', $policy->normalizedName("  Nebula   Sprawl \n"));
        self::assertNull($policy->normalizedOptional('   '), 'un genre vide n\'est pas un genre');
        self::assertSame('métal', $policy->normalizedOptional(' métal '));
        self::assertNull($policy->normalizedOptional(null));
    }

    #[Test]
    public function testAValidProfileHasNoViolation(): void
    {
        self::assertSame([], (new GroupInputPolicy())->profileViolations(
            [new LineupMember('Alice', 'Guitare'), new LineupMember('Bob', 'Batterie')],
            [new UpcomingShow('2026-12-24', 'Le Bataclan')],
        ));
        self::assertSame([], (new GroupInputPolicy())->profileViolations([], []));
    }

    #[Test]
    public function testTheProfileIsBoundedSoItCannotBecomeAMegabyteOfJson(): void
    {
        $policy = new GroupInputPolicy();
        $tooManyMembers = array_map(static fn (int $i): LineupMember => new LineupMember("Membre {$i}", 'Basse'), range(1, 21));
        $tooManyShows = array_map(static fn (int $i): UpcomingShow => new UpcomingShow('2026-12-24', "Salle {$i}"), range(1, 21));

        self::assertArrayHasKey('lineup', $policy->profileViolations($tooManyMembers, []));
        self::assertArrayHasKey('upcomingShows', $policy->profileViolations([], $tooManyShows));
        self::assertSame([], $policy->profileViolations(array_slice($tooManyMembers, 0, 20), array_slice($tooManyShows, 0, 20)), '20 de chaque : accepté');
    }

    #[Test]
    public function testLineupAndShowsFieldsAreBoundedAndNonEmpty(): void
    {
        $policy = new GroupInputPolicy();

        self::assertArrayHasKey('lineup', $policy->profileViolations([new LineupMember('', 'Basse')], []));
        self::assertArrayHasKey('lineup', $policy->profileViolations([new LineupMember(str_repeat('n', 101), 'Basse')], []));
        self::assertArrayHasKey('lineup', $policy->profileViolations([new LineupMember('Alice', str_repeat('i', 101))], []));
        self::assertArrayHasKey('upcomingShows', $policy->profileViolations([], [new UpcomingShow('2026-12-24', '')]));
        self::assertArrayHasKey('upcomingShows', $policy->profileViolations([], [new UpcomingShow('2026-12-24', str_repeat('v', 101))]));
    }

    /** @return iterable<string, array{string}> */
    public static function badDates(): iterable
    {
        yield 'texte libre' => ['bientôt'];
        yield 'format français' => ['24/12/2026'];
        yield 'jour impossible' => ['2026-02-30'];
        yield 'mois impossible' => ['2026-13-01'];
        yield 'date avec heure' => ['2026-12-24 20:00'];
        yield 'vide' => [''];
    }

    #[Test]
    #[DataProvider('badDates')]
    public function testAShowDateMustBeARealCalendarDay(string $date): void
    {
        self::assertArrayHasKey('upcomingShows', (new GroupInputPolicy())->profileViolations([], [new UpcomingShow($date, 'Salle')]));
    }
}
