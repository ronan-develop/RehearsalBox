<?php

declare(strict_types=1);

namespace App\Metrics\Collection;

use App\Metrics\MetricEventType;

/** Quel évènement ponctuel (s'il y en a un) correspond à une réponse : table unique, pure, testée. */
final class EventClassifier
{
    private const LOGIN_PATH = '/api/auth/login';

    public static function forResponse(string $method, string $path, int $status, bool $csrfRefused): ?MetricEventType
    {
        return match (true) {
            $csrfRefused => MetricEventType::CsrfFailed,
            $status >= 500 => MetricEventType::ServerError,
            $status === 429 => MetricEventType::RateLimited,
            $status === 403 => MetricEventType::AccessDenied,
            $status === 404 => MetricEventType::NotFound,
            $status === 401 && $method === 'POST' && $path === self::LOGIN_PATH => MetricEventType::LoginFailed,
            default => null,
        };
    }
}
