<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Throttle;

use App\Account\Repository\MysqlThrottleEventRepository;
use App\Account\Service\Throttle\SubjectThrottle;
use App\Account\Service\Throttle\LoginThrottle;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * #236 : le blocage de connexion annonce une durée, la même pour un compte réel et pour une adresse inventée. #441 : il se compte par
 * adresse, par COUPLE (adresse, identifiant) et par identifiant (limite haute), pour qu'une seule adresse ne puisse pas bloquer
 * la connexion d'une autre personne.
 */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class LoginThrottleTest extends RepositoryTestCase
{
    private const IP_LIMIT = 20;
    private const ID_LIMIT = 10;
    private const PAIR_LIMIT = 2;

    private LoginThrottle $throttle;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $events = new MysqlThrottleEventRepository($this->pdo);
        $this->throttle = new LoginThrottle(
            new SubjectThrottle($events, 'login', self::IP_LIMIT, '-15 minutes'),
            new SubjectThrottle($events, 'login-id', self::ID_LIMIT, '-15 minutes'),
            new SubjectThrottle($events, 'login-pair', self::PAIR_LIMIT, '-15 minutes'),
        );
        $this->now = new \DateTimeImmutable('2026-01-01 10:00:00');
    }

    private function failures(string $ip, string $email, int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->throttle->recordFailure($ip, $email, $this->now);
        }
    }

    #[Test]
    public function testNothingIsAnnouncedBeforeTheLimit(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::PAIR_LIMIT - 1);

        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
    }

    #[Test]
    public function testAnAddressThatKeepsFailingOnOneIdentifierIsBlockedForThatIdentifierOnly(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::PAIR_LIMIT);

        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
        self::assertSame(540, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now->modify('+6 minutes')));
        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', 'bob@rehearsalbox.test', $this->now), 'un autre identifiant depuis la même adresse reste libre');
    }

    #[Test]
    public function testOneAddressCannotLockAnotherPersonOutOfTheirAccount(): void
    {
        // L'attaquant s'acharne sur l'adresse e-mail de la victime : lui seul est bloqué (#441).
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::PAIR_LIMIT + 3);

        self::assertGreaterThan(0, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now), 'la victime, depuis son adresse, se connecte');
    }

    #[Test]
    public function testAnIdentifierHammeredFromManyAddressesIsBlockedWhateverTheAddress(): void
    {
        for ($i = 1; $i <= self::ID_LIMIT; ++$i) {
            $this->failures("203.0.113.{$i}", 'alice@rehearsalbox.test');
        }

        self::assertSame(900, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now), 'attaque répartie : tout le monde attend');
    }

    #[Test]
    public function testAKnownAccountAndAnInventedAddressGetExactlyTheSameAnswer(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::PAIR_LIMIT);
        $this->failures('203.0.113.7', 'personne-ne-porte-ce-nom@rehearsalbox.test', self::PAIR_LIMIT);

        foreach ([0, 120, 600] as $seconds) {
            $at = $this->now->modify("+{$seconds} seconds");
            self::assertSame(
                $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $at),
                $this->throttle->remainingSeconds('203.0.113.7', 'personne-ne-porte-ce-nom@rehearsalbox.test', $at),
                "même durée à +{$seconds} s : rien ne révèle quel identifiant existe",
            );
        }
    }

    #[Test]
    public function testTheIdentifierIsNormalizedSoCaseAndSpacesCannotDodgeTheLimit(): void
    {
        $this->failures('203.0.113.7', 'Alice@Rehearsalbox.test');
        $this->failures('203.0.113.7', '  alice@rehearsalbox.test ');

        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.7', 'ALICE@REHEARSALBOX.TEST', $this->now));
    }

    #[Test]
    public function testAnAddressThatKeepsFailingIsBlockedWhateverTheIdentifier(): void
    {
        for ($i = 0; $i < self::IP_LIMIT; ++$i) {
            $this->failures('203.0.113.7', "personne{$i}@rehearsalbox.test");
        }

        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now));
    }

    #[Test]
    public function testWhenSeveralLimitsBlockTheLongestWaitIsAnnounced(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::PAIR_LIMIT); // couple bloqué à 10:00
        $later = $this->now->modify('+10 minutes');
        for ($i = 0; $i < self::IP_LIMIT; ++$i) {
            $this->throttle->recordFailure('203.0.113.7', "autre{$i}@rehearsalbox.test", $later); // adresse bloquée à 10:10
        }

        // À 10:10 : le couple a encore 5 min, l'adresse 15 min.
        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $later));
    }

    #[Test]
    public function testForgettingAnIdentifierLiftsItsBlocksButNotTheAddressOne(): void
    {
        for ($i = 0; $i < self::IP_LIMIT; ++$i) {
            $this->failures('203.0.113.7', 'alice@rehearsalbox.test');
        }
        self::assertGreaterThan(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now));

        $this->throttle->forgetIdentifier('ALICE@rehearsalbox.test', '203.0.113.7');

        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now), 'autre adresse : libre');
        self::assertGreaterThan(0, $this->throttle->remainingSeconds('203.0.113.7', 'bob@rehearsalbox.test', $this->now), 'l\'adresse reste bloquée');
    }

    #[Test]
    public function testAnAdministratorUnlockWithoutAnAddressLiftsEveryLimitOfThatIdentifier(): void
    {
        for ($i = 1; $i <= self::ID_LIMIT; ++$i) {
            $this->failures("203.0.113.{$i}", 'alice@rehearsalbox.test');
        }
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::PAIR_LIMIT);

        $this->throttle->forgetIdentifier('alice@rehearsalbox.test');

        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now), 'limite par identifiant levée');
        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now), 'et celle de son couple, sans connaître l\'adresse');
    }

    #[Test]
    public function testAnEmptyIdentifierOrAddressIsNeverCountedNorBlocked(): void
    {
        $this->failures('', '', 20);

        self::assertSame(0, $this->throttle->remainingSeconds('', '', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
    }
}
