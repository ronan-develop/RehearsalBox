<?php

declare(strict_types=1);

namespace App\Tests\Account\Service\Throttle;

use App\Account\Repository\MysqlThrottleEventRepository;
use App\Account\Service\Throttle\SubjectThrottle;
use App\Account\Service\Throttle\LoginThrottle;
use App\Tests\Database\RepositoryTestCase;
use PHPUnit\Framework\Attributes\Test;

/** #236 : le blocage de connexion (par adresse IP ET par identifiant saisi) annonce une durée, la même pour un compte réel et pour une adresse inventée. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class LoginThrottleTest extends RepositoryTestCase
{
    private const IP_LIMIT = 6;
    private const ID_LIMIT = 3;

    private LoginThrottle $throttle;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $events = new MysqlThrottleEventRepository($this->pdo);
        $this->throttle = new LoginThrottle(
            new SubjectThrottle($events, 'login', self::IP_LIMIT, '-15 minutes'),
            new SubjectThrottle($events, 'login-id', self::ID_LIMIT, '-15 minutes'),
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
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::ID_LIMIT - 1);

        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
    }

    #[Test]
    public function testAnIdentifierThatKeepsFailingIsBlockedWithTheRemainingTimeWhateverTheAddress(): void
    {
        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3'] as $ip) {
            $this->failures($ip, 'alice@rehearsalbox.test');
        }

        self::assertSame(900, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now), 'depuis une autre adresse aussi');
        self::assertSame(540, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now->modify('+6 minutes')));
    }

    #[Test]
    public function testAKnownAccountAndAnInventedAddressGetExactlyTheSameAnswer(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::ID_LIMIT);
        $this->failures('203.0.113.8', 'personne-ne-porte-ce-nom@rehearsalbox.test', self::ID_LIMIT);

        foreach ([0, 120, 600] as $seconds) {
            $at = $this->now->modify("+{$seconds} seconds");
            self::assertSame(
                $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $at),
                $this->throttle->remainingSeconds('198.51.100.9', 'personne-ne-porte-ce-nom@rehearsalbox.test', $at),
                "même durée à +{$seconds} s : rien ne révèle quel identifiant existe",
            );
        }
    }

    #[Test]
    public function testTheIdentifierIsNormalizedSoCaseAndSpacesCannotDodgeTheLimit(): void
    {
        $this->failures('203.0.113.1', 'Alice@Rehearsalbox.test');
        $this->failures('203.0.113.2', '  alice@rehearsalbox.test ');
        $this->failures('203.0.113.3', 'ALICE@REHEARSALBOX.TEST');

        self::assertSame(900, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now));
    }

    #[Test]
    public function testAnotherIdentifierIsNotAffected(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::ID_LIMIT);

        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'bob@rehearsalbox.test', $this->now));
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
    public function testWhenBothLimitsBlockTheLongerWaitIsAnnounced(): void
    {
        $this->failures('203.0.113.7', 'alice@rehearsalbox.test', self::ID_LIMIT); // identifiant bloqué à 10:00
        $later = $this->now->modify('+10 minutes');
        for ($i = 0; $i < self::IP_LIMIT; ++$i) {
            $this->throttle->recordFailure('203.0.113.9', "autre{$i}@rehearsalbox.test", $later); // adresse bloquée à 10:10
        }

        // À 10:10 : l'identifiant a encore 5 min, l'adresse 15 min.
        self::assertSame(900, $this->throttle->remainingSeconds('203.0.113.9', 'alice@rehearsalbox.test', $later));
    }

    #[Test]
    public function testForgettingAnIdentifierLiftsItsBlockButNotTheAddressOne(): void
    {
        for ($i = 0; $i < self::IP_LIMIT; ++$i) {
            $this->failures('203.0.113.7', 'alice@rehearsalbox.test');
        }
        self::assertGreaterThan(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now));

        $this->throttle->forgetIdentifier('ALICE@rehearsalbox.test');

        self::assertSame(0, $this->throttle->remainingSeconds('198.51.100.9', 'alice@rehearsalbox.test', $this->now), 'autre adresse : libre');
        self::assertGreaterThan(0, $this->throttle->remainingSeconds('203.0.113.7', 'bob@rehearsalbox.test', $this->now), 'l\'adresse reste bloquée');
    }

    #[Test]
    public function testAnEmptyIdentifierOrAddressIsNeverCountedNorBlocked(): void
    {
        $this->failures('', '', 20);

        self::assertSame(0, $this->throttle->remainingSeconds('', '', $this->now));
        self::assertSame(0, $this->throttle->remainingSeconds('203.0.113.7', 'alice@rehearsalbox.test', $this->now));
    }
}
