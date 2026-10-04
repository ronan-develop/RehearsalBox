<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\Exception\UserValidationException;
use App\Service\PasswordResetService;

final class PasswordResetApiController
{
    public function __construct(private readonly PasswordResetService $passwordReset)
    {
    }

    /** Réponse identique que le compte existe ou non : aucune énumération possible. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $email = trim((string) $request->body('email', ''));
        if ($email === '') {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['email' => 'Adresse email requise.']], 422);
        }

        $this->passwordReset->requestReset($email);

        return new JsonResponse(['status' => 'ok']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $token = (string) $request->body('token', '');
        $password = (string) $request->body('password', '');

        if ($token === '') {
            return new JsonResponse(['error' => (new InvalidResetTokenException())->getMessage()], 422);
        }

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
