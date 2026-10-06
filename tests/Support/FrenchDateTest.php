<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\FrenchDate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrenchDateTest extends TestCase
{
    #[Test]
    public function testALongDateIsWrittenInFrenchWithoutDependingOnTheServerLocale(): void
    {
        self::assertSame('mercredi 7 octobre 2026', FrenchDate::long(new \DateTimeImmutable('2026-10-07')));
        self::assertSame('lundi 1 février 2027', FrenchDate::long(new \DateTimeImmutable('2027-02-01')), 'le premier du mois sans zéro ni « er »');
        self::assertSame('dimanche 27 décembre 2026', FrenchDate::long(new \DateTimeImmutable('2026-12-27')));
        self::assertSame('samedi 14 août 2027', FrenchDate::long(new \DateTimeImmutable('2027-08-14')));
    }
}
