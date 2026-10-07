<?php

declare(strict_types=1);

namespace App\Metrics;

/** Les évènements ponctuels retenus pour les rapports (#195) : de quoi suivre la sécurité et les e-mails, rien de plus. */
enum MetricEventType: string
{
    case LoginFailed = 'login_failed';
    case AccessDenied = 'access_denied';
    case CsrfFailed = 'csrf_failed';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    case PasswordResetRequested = 'password_reset_requested';
    case MailSent = 'mail_sent';
    case MailFailed = 'mail_failed';
}
