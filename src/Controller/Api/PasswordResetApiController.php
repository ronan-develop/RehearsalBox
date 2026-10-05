<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\IpThrottle;
use App\Service\Exception\UserValidationException;
use App\Service\PasswordResetService;

final class PasswordResetApiController
{
    public function __construct(
        private readonly PasswordResetService $passwordReset,
        private readonly IpThrottle $throttle,
    ) {
    }

    /** Réponse identique et instantanée que le compte existe ou non (le travail suit la réponse) : aucune énumération possible. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $email = trim((string) $request->body('email', ''));
        if ($email === '') {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['email' => 'Adresse email requise.']], 422);
        }

        // Limite par adresse (#219), indépendante de tout compte : chaque demande compte, qu'il existe ou non.
        $now = new \DateTimeImmutable();
        $ip = $request->clientIp();
        if ($this->throttle->isBlocked($ip, $now)) {
            return new JsonResponse(
                ['error' => 'Trop de demandes. Réessayez dans un moment.'],
                429,
                ['Retry-After' => (string) $this->throttle->retryAfterSeconds()],
            );
        }
        $this->throttle->record($ip, $now);

        $this->passwordReset->requestReset($email);

        return new JsonResponse(['status' => 'ok']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $token = (string) $request->body('token', '');
        $password = (string) $request->body('password', '');

        try {
            $this->passwordReset->resetPassword($token, $password);
        } catch (UserValidationException $e) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => $e->fields()], 422);
        } catch (InvalidResetTokenException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
