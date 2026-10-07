<?php

declare(strict_types=1);

namespace App\Tests\Metrics\Collection;

use App\Metrics\Collection\EventClassifier;
use App\Metrics\MetricEventType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventClassifierTest extends TestCase
{
    /** @return array<string, array{string, string, int, bool, ?MetricEventType}> */
    public static function responses(): array
    {
        return [
            'page servie' => ['GET', '/login', 200, false, null],
            'redirection' => ['GET', '/', 302, false, null],
            'erreur serveur' => ['GET', '/x', 500, false, MetricEventType::ServerError],
            'limite de débit' => ['POST', '/api/auth/login', 429, false, MetricEventType::RateLimited],
            'accès refusé' => ['GET', '/admin', 403, false, MetricEventType::AccessDenied],
            'jeton CSRF' => ['POST', '/api/x', 403, true, MetricEventType::CsrfFailed],
            'introuvable' => ['GET', '/.env', 404, false, MetricEventType::NotFound],
            'connexion échouée' => ['POST', '/api/auth/login', 401, false, MetricEventType::LoginFailed],
            'demande de mot de passe oublié' => ['POST', '/api/auth/forgot-password', 200, false, MetricEventType::PasswordResetRequested],
            'mot de passe oublié limité' => ['POST', '/api/auth/forgot-password', 429, false, MetricEventType::RateLimited],
            '401 ailleurs (session expirée)' => ['GET', '/api/conversations', 401, false, null],
        ];
    }

    #[Test]
    #[DataProvider('responses')]
    public function testEachResponseMapsToItsEvent(string $method, string $path, int $status, bool $csrf, ?MetricEventType $expected): void
    {
        self::assertSame($expected, EventClassifier::forResponse($method, $path, $status, $csrf));
    }
}
