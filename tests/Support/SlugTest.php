<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SlugTest extends TestCase
{
    #[Test]
    public function testSlugifyLowercasesAndReplacesSpacesWithDashes(): void
    {
        self::assertSame('black-sabbath-tribute', Slug::from('Black Sabbath Tribute'));
    }

    #[Test]

    public function testSlugifyStripsAccents(): void
    {
        self::assertSame('les-decibels-enerves', Slug::from('Les Décibels Énervés'));
    }

    #[Test]

    public function testSlugifyCollapsesMultipleSeparators(): void
    {
        self::assertSame('groupe-test', Slug::from('Groupe   --  Test'));
    }

    #[Test]

    public function testSlugifyTrimsLeadingAndTrailingDashes(): void
    {
        self::assertSame('groupe-test', Slug::from('  Groupe Test!  '));
    }
}
