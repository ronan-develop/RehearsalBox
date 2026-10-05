<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Http\JsonResponse;
use App\Http\RedirectResponse;
use App\Http\Response;
use App\Security\SecurityHeaders;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function testEveryResponseCarriesTheBaselineProtections(): void
    {
        $headers = (new SecurityHeaders())->applyTo(new Response('ok'))->headers();

        self::assertSame('DENY', $headers['X-Frame-Options']);
        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('strict-origin-when-cross-origin', $headers['Referrer-Policy']);
        self::assertSame('private, no-store', $headers['Cache-Control']);
        self::assertStringContainsString('camera=()', $headers['Permissions-Policy']);
        self::assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
    }

    #[Test]
    public function testTheContentSecurityPolicyForbidsFramingAndInlineScripts(): void
    {
        $policy = (new SecurityHeaders())->applyTo(new Response('ok'))->headers()['Content-Security-Policy'];

        self::assertStringContainsString("frame-ancestors 'none'", $policy);
        self::assertStringContainsString("object-src 'none'", $policy);
        self::assertStringContainsString("base-uri 'self'", $policy);
        self::assertStringContainsString("form-action 'self'", $policy);
        self::assertStringContainsString("script-src 'self'", $policy);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
    }

    #[Test]
    public function testInlineStyleAttributesAreTheOnlyInlineAllowance(): void
    {
        $policy = (new SecurityHeaders())->applyTo(new Response('ok'))->headers()['Content-Security-Policy'];

        self::assertStringContainsString("style-src 'self'", $policy);
        self::assertStringContainsString("style-src-attr 'unsafe-inline'", $policy);
        self::assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $policy);
    }

    #[Test]
    public function testStrictTransportSecurityIsSentOnlyWhenEnabled(): void
    {
        $off = (new SecurityHeaders())->applyTo(new Response('ok'))->headers();
        $on = (new SecurityHeaders(hsts: true))->applyTo(new Response('ok'))->headers();

        self::assertArrayNotHasKey('Strict-Transport-Security', $off);
        self::assertStringStartsWith('max-age=', $on['Strict-Transport-Security']);
        self::assertStringNotContainsString('includeSubDomains', $on['Strict-Transport-Security']);
    }

    #[Test]
    public function testHeadersSetByTheControllerWinOverTheDefaultsWhateverTheirCase(): void
    {
        $response = new Response('ok', 200, ['referrer-policy' => 'no-referrer', 'Cache-Control' => 'public, max-age=60']);

        $headers = (new SecurityHeaders())->applyTo($response)->headers();

        self::assertSame('no-referrer', $headers['referrer-policy']);
        self::assertArrayNotHasKey('Referrer-Policy', $headers);
        self::assertSame('public, max-age=60', $headers['Cache-Control']);
    }

    #[Test]
    public function testTheKindAndContentOfTheResponseAreKept(): void
    {
        $json = (new SecurityHeaders())->applyTo(new JsonResponse(['a' => 1], 403));
        $redirect = (new SecurityHeaders())->applyTo(new RedirectResponse('/login'));

        self::assertInstanceOf(JsonResponse::class, $json);
        self::assertSame('{"a":1}', $json->body());
        self::assertSame(403, $json->statusCode());
        self::assertStringContainsString('application/json', $json->headers()['Content-Type']);
        self::assertInstanceOf(RedirectResponse::class, $redirect);
        self::assertSame('/login', $redirect->headers()['Location']);
    }

    #[Test]
    public function testTheOriginalResponseIsNotMutated(): void
    {
        $original = new Response('ok');

        (new SecurityHeaders())->applyTo($original);

        self::assertSame([], $original->headers());
    }
}
