<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\NativeSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NativeSessionTest extends TestCase
{
    #[Test]
    public function testCookieOptionsAreSecureWhenHttpsIsOn(): void
    {
        $options = NativeSession::cookieOptions(['HTTPS' => 'on']);

        self::assertTrue($options['cookie_secure']);
        self::assertTrue($options['cookie_httponly']);
        self::assertSame('Lax', $options['cookie_samesite']);
    }

    #[Test]
    public function testCookieOptionsAreSecureBehindProxyForwardingHttps(): void
    {
        $options = NativeSession::cookieOptions(['HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertTrue($options['cookie_secure']);
    }

    #[Test]
    public function testCookieOptionsAreNotSecureOverPlainHttp(): void
    {
        $options = NativeSession::cookieOptions(['HTTPS' => 'off']);

        self::assertFalse($options['cookie_secure']);
        self::assertTrue($options['cookie_httponly']);
    }

    #[Test]
    public function testCookieOptionsAreNotSecureWithoutAnyHttpsIndicator(): void
    {
        self::assertFalse(NativeSession::cookieOptions([])['cookie_secure']);
    }
}
