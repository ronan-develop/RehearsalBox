<?php

declare(strict_types=1);

namespace App\Metrics\Collection;

use App\Http\Response;
use App\Metrics\MetricEventType;

/** Quel évènement ponctuel (s'il y en a un) correspond à une réponse : table unique, pure, testée. */
final class EventClassifier
{
    private const LOGIN_PATH = '/api/auth/login';
    private const FORGOT_PASSWORD_PATH = '/api/auth/forgot-password';

    public static function forResponse(string $method, string $path, int $status, bool $csrfRefused): ?MetricEventType
    {
        return match (true) {
            $csrfRefused => MetricEventType::CsrfFailed,
            $status >= 500 => MetricEventType::ServerError,
            in_array($status, [Response::RATE_LIMITED, 429], true) => MetricEventType::RateLimited,
            $status === 403 => MetricEventType::AccessDenied,
            $status === 404 => MetricEventType::NotFound,
            $status === 401 && $method === 'POST' && $path === self::LOGIN_PATH => MetricEventType::LoginFailed,
            $status < 400 && $method === 'POST' && $path === self::FORGOT_PASSWORD_PATH => MetricEventType::PasswordResetRequested,
            default => null,
        };
    }
}
