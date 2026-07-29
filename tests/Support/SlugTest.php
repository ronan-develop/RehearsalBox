<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public function testSlugifyLowercasesAndReplacesSpacesWithDashes(): void
    {
        self::assertSame('black-sabbath-tribute', Slug::from('Black Sabbath Tribute'));
    }

    public function testSlugifyStripsAccents(): void
    {
        self::assertSame('les-decibels-enerves', Slug::from('Les Décibels Énervés'));
    }

    public function testSlugifyCollapsesMultipleSeparators(): void
    {
        self::assertSame('groupe-test', Slug::from('Groupe   --  Test'));
    }

    public function testSlugifyTrimsLeadingAndTrailingDashes(): void
    {
        self::assertSame('groupe-test', Slug::from('  Groupe Test!  '));
    }
}
