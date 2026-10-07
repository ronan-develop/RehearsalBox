<?php

declare(strict_types=1);

namespace App\Tests\Metrics;

use App\Metrics\IpPseudonymizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IpPseudonymizerTest extends TestCase
{
    #[Test]
    public function testTheFingerprintIsStableAndShortAndDoesNotContainTheAddress(): void
    {
        $ips = new IpPseudonymizer('secret-du-serveur');

        $first = $ips->of('203.0.113.7');

        self::assertSame($first, $ips->of('203.0.113.7'), 'un même visiteur garde la même empreinte (rafales repérables)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $first);
        self::assertNotSame($first, $ips->of('203.0.113.8'));
        self::assertStringNotContainsString('203', (string) $first);
    }

    #[Test]
    public function testItCannotBeRecomputedWithoutTheSecret(): void
    {
        $fingerprint = (new IpPseudonymizer('secret-du-serveur'))->of('203.0.113.7');

        self::assertNotSame($fingerprint, substr(hash('sha256', '203.0.113.7'), 0, 16), 'pas un simple hachage');
        self::assertNotSame($fingerprint, (new IpPseudonymizer('autre-secret'))->of('203.0.113.7'), 'dépend du secret');
    }

    #[Test]
    public function testWithoutASecretOrWithoutAnAddressNothingIsKept(): void
    {
        self::assertNull((new IpPseudonymizer(''))->of('203.0.113.7'));
        self::assertNull((new IpPseudonymizer('secret'))->of(''));
    }
}
