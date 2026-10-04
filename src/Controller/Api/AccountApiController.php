<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\AccountSecurityService;
use App\Service\Contract\AuthServiceInterface;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\Exception\UserValidationException;
use App\Service\PasswordChangeService;

final class AccountApiController
{
    public function __construct(
        private readonly AuthGuard $authGuard,
        private readonly AuthServiceInterface $authService,
        private readonly PasswordChangeService $passwordChange,
        private readonly AccountSecurityService $accountSecurity,
    ) {
    }

    /** L'utilisateur visé est celui de la session : aucun identifiant n'est lu dans la requête. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        try {
            $updated = $this->passwordChange->changePassword(
                $user->id(),
                (string) $request->body('currentPassword', ''),
                (string) $request->body('password', ''),
                (string) $request->body('passwordConfirmation', ''),
            );
        } catch (UserValidationException $e) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => $e->fields()], 422);
        }

        // Les autres appareils sont déconnectés ; celui-ci reste connecté (nouvel identifiant de session).
        $this->authService->refreshSession($updated);

        return new JsonResponse(['status' => 'ok']);
    }

    /** Bouton « Ce n'est pas moi » du mail d'alerte : public (le jeton fait foi), appelé après confirmation. */
    public function secureAccount(Request $request): JsonResponse
    {
        try {
            $this->accountSecurity->secureAccount((string) $request->body('token', ''));
        } catch (InvalidResetTokenException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => 'ok']);
    }
}
