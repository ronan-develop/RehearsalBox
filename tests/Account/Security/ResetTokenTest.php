<?php

declare(strict_types=1);

namespace App\Tests\Account\Security;

use App\Account\Security\ResetToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResetTokenTest extends TestCase
{
    #[Test]
    public function testGenerateReturnsA256BitHexToken(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', ResetToken::generate());
    }

    #[Test]
    public function testGenerateNeverRepeats(): void
    {
        self::assertNotSame(ResetToken::generate(), ResetToken::generate());
    }

    #[Test]
    public function testHashIsTheSha256HexDigestOfTheToken(): void
    {
        self::assertSame(hash('sha256', 'abc'), ResetToken::hash('abc'));
    }

    #[Test]
    public function testTheStoredHashIsNeverTheTokenItself(): void
    {
        $token = ResetToken::generate();

        self::assertNotSame($token, ResetToken::hash($token));
    }
}
