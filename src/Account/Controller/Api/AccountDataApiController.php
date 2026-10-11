<?php

declare(strict_types=1);

namespace App\Account\Controller\Api;

use App\Account\Exception\UserValidationException;
use App\Account\Service\Data\AccountDataExporter;
use App\Account\Service\Data\AccountDeletionService;
use App\Account\Service\AuthServiceInterface;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;

/**
 * Mes données (#431) : export et suppression de MON compte. Le compte est celui de la session : aucun identifiant n'est lu dans la
 * requête (un autre compte n'est jamais adressable par cette route).
 */
final class AccountDataApiController
{
    public const CONFIRM_WORD = 'SUPPRIMER';

    public function __construct(
        private readonly AuthGuard $authGuard,
        private readonly AuthServiceInterface $authService,
        private readonly AccountDataExporter $exporter,
        private readonly AccountDeletionService $deletion,
    ) {
    }

    /** Téléchargement JSON de mes données (jamais mis en cache : politique par défaut du Kernel). */
    public function export(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        return new JsonResponse(
            $this->exporter->export($user->id()),
            200,
            ['Content-Disposition' => 'attachment; filename="rehearsalbox-mes-donnees.json"'],
        );
    }

    /** Suppression de mon compte : mot de passe actuel ET mot de confirmation ; la session est fermée ensuite. */
    public function delete(Request $request): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $password = $request->body('password');
        if ($request->body('confirmation') !== self::CONFIRM_WORD) {
            throw new UserValidationException(['confirmation' => 'Saisissez « ' . self::CONFIRM_WORD . ' » pour confirmer.']);
        }
        if (!is_string($password) || $password === '') {
            throw new UserValidationException(['currentPassword' => 'Votre mot de passe est requis.']);
        }

        $this->deletion->delete($user->id(), $password);
        $this->authService->logout();

        return new JsonResponse([], 204);
    }
}
