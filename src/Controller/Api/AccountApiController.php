<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Service\AccountSecurityService;
use App\Service\Contract\AuthServiceInterface;
use App\Service\EmailChangeService;
use App\Service\Exception\InvalidEmailChangeException;
use App\Service\PasswordChangeService;
use App\Service\ProfileService;

final class AccountApiController
{
    public function __construct(
        private readonly AuthGuard $authGuard,
        private readonly AuthServiceInterface $authService,
        private readonly PasswordChangeService $passwordChange,
        private readonly AccountSecurityService $accountSecurity,
        private readonly ProfileService $profile,
        private readonly EmailChangeService $emailChange,
    ) {
    }

    /**
     * Mon nom affiché (#161). Le compte modifié est celui de la session : ni identifiant, ni e-mail,
     * ni rôle, ni état ne sont lus dans la requête (un seul champ : displayName).
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $displayName = $request->body('displayName');
        if (!is_string($displayName)) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['displayName' => 'Le nom affiché est requis.']], 422);
        }

        $updated = $this->profile->updateDisplayName($user->id(), $displayName);

        return new JsonResponse(['displayName' => $updated->displayName()]);
    }

    /**
     * Recevoir ou non les e-mails de mention (#178) : MA préférence (celle de la session, aucun identifiant lu dans la
     * requête). Valeur claire exigée : booléen, « 1 »/« 0 » (boutons radio) ou « true »/« false ».
     */
    public function updateNotifications(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $enabled = match ($request->body('emailNotifications')) {
            true, 1, '1', 'true' => true,
            false, 0, '0', 'false' => false,
            default => null,
        };
        if ($enabled === null) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['emailNotifications' => 'Choisissez recevoir ou ne pas recevoir ces e-mails.']], 422);
        }

        $this->profile->updateEmailNotifications($user->id(), $enabled);

        return new JsonResponse(['emailNotifications' => $enabled]);
    }

    /**
     * Demande de changement de MON adresse e-mail (#164) : nouvelle adresse + mot de passe actuel. Le compte est
     * celui de la session (aucun identifiant lu dans la requête). Réponse identique que l'adresse soit libre ou
     * déjà utilisée : on ne révèle pas quelles adresses ont un compte. Le jeton ne sort jamais de l'e-mail.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $email = $request->body('email');
        $currentPassword = $request->body('currentPassword', '');
        if (!is_string($email)) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['email' => 'Adresse email invalide.']], 422);
        }
        if (!is_string($currentPassword)) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['currentPassword' => 'Mot de passe actuel incorrect.']], 422);
        }

        $this->emailChange->requestChange($user->id(), $currentPassword, $email);

        return new JsonResponse(['status' => 'ok']);
    }

    /** Confirmation depuis le lien reçu à la nouvelle adresse : publique (le jeton fait foi), appelée après un clic explicite. */
    public function confirmEmailChange(Request $request): JsonResponse
    {
        $token = $request->body('token');
        if (!is_string($token)) {
            return new JsonResponse(['error' => (new InvalidEmailChangeException())->getMessage()], 422);
        }

        $this->emailChange->confirm($token);

        return new JsonResponse(['status' => 'ok']);
    }

    /** L'utilisateur visé est celui de la session : aucun identifiant n'est lu dans la requête. */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $updated = $this->passwordChange->changePassword(
            $user->id(),
            (string) $request->body('currentPassword', ''),
            (string) $request->body('password', ''),
            (string) $request->body('passwordConfirmation', ''),
        );

        // Les autres appareils sont déconnectés ; celui-ci reste connecté (nouvel identifiant de session).
        $this->authService->refreshSession($updated);

        return new JsonResponse(['status' => 'ok']);
    }

    /** Bouton « Ce n'est pas moi » du mail d'alerte : public (le jeton fait foi), appelé après confirmation. */
    public function secureAccount(Request $request): JsonResponse
    {
        $this->accountSecurity->secureAccount((string) $request->body('token', ''));

        return new JsonResponse(['status' => 'ok']);
    }
}
