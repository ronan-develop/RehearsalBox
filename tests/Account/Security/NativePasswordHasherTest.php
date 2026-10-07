<?php

declare(strict_types=1);

namespace App\Tests\Account\Security;

use App\Account\Security\NativePasswordHasher;
use App\Tests\Support\FastPasswordHasher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Le hacheur de production n'est jamais affaibli : les tests utilisent FastPasswordHasher, celui-ci protège le vrai. */
final class NativePasswordHasherTest extends TestCase
{
    #[Test]
    public function testAHashVerifiesWithTheRightPasswordOnly(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('mot-de-passe-solide');

        self::assertTrue($hasher->verify('mot-de-passe-solide', $hash));
        self::assertFalse($hasher->verify('autre-mot-de-passe', $hash));
        self::assertStringNotContainsString('mot-de-passe-solide', $hash);
    }

    #[Test]
    public function testEveryHashIsSaltedSoTwoHashesOfTheSamePasswordDiffer(): void
    {
        $hasher = new NativePasswordHasher();

        self::assertNotSame($hasher->hash('identique'), $hasher->hash('identique'));
    }

    #[Test]
    public function testProductionHashesUseAtLeastTheDefaultCost(): void
    {
        $info = password_get_info((new NativePasswordHasher())->hash('x'));

        self::assertSame('bcrypt', $info['algoName']);
        self::assertGreaterThanOrEqual(PASSWORD_BCRYPT_DEFAULT_COST, $info['options']['cost'], 'le coût de production ne doit jamais baisser');
    }

    #[Test]
    public function testTheFastTestHasherIsInterchangeableWithTheRealOne(): void
    {
        $real = new NativePasswordHasher();
        $fast = new FastPasswordHasher();

        self::assertTrue($real->verify('secret', $fast->hash('secret')), 'un hachage de test se vérifie avec le vrai hacheur');
        self::assertTrue($fast->verify('secret', $real->hash('secret')));
        self::assertSame(4, password_get_info($fast->hash('x'))['options']['cost']);
    }

    #[Test]
    public function testSimulatedVerificationCostsAboutAsMuchAsARealOne(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('mot-de-passe');

        $real = $this->fastest(static fn () => $hasher->verify('autre', $hash));
        $simulated = $this->fastest(static fn () => $hasher->simulateVerification('autre'));

        // Sans cela, un compte inconnu répondrait ~100 fois plus vite qu'un compte existant (énumération par temps de réponse).
        self::assertGreaterThan($real * 0.5, $simulated);
        self::assertLessThan($real * 2.0, $simulated);
    }

    /** Meilleur de trois mesures, en secondes (réduit le bruit d'une machine chargée). */
    private function fastest(callable $operation): float
    {
        $best = INF;
        for ($i = 0; $i < 3; ++$i) {
            $start = hrtime(true);
            $operation();
            $best = min($best, (hrtime(true) - $start) / 1e9);
        }

        return $best;
    }
}
