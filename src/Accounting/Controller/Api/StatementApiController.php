<?php

declare(strict_types=1);

namespace App\Accounting\Controller\Api;

use App\Account\Entity\UserRole;
use App\Account\Service\Throttle\UserActionLimit;
use App\Accounting\Service\StatementService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * Dépôt d'un relevé de compte (#362), administrateurs uniquement (garde ici ET dans le service) ; jeton CSRF exigé par le noyau. L'exercice
 * vient du corps mais doit être strictement numérique (sinon refus comme un accès interdit) ; nom stocké, empreinte, type, auteur et
 * remplacement ne viennent jamais du client (le service les fixe). Erreurs métier traduites par ExceptionTranslator.
 */
final class StatementApiController
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'Le fichier dépasse la taille maximale autorisée (10 Mo).',
        UPLOAD_ERR_FORM_SIZE => 'Le fichier dépasse la taille maximale autorisée (10 Mo).',
        UPLOAD_ERR_PARTIAL => 'Le fichier n\'est pas arrivé en entier : réessayez.',
    ];

    public function __construct(
        private readonly StatementService $statements,
        private readonly AuthGuard $authGuard,
        private readonly ?UserActionLimit $uploadLimit = null,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $yearId = StrictId::orDenied($request->body('fiscalYearId'));
        $this->uploadLimit?->hit($admin->id());

        $file = $request->file('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $message = self::UPLOAD_ERRORS[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Choisissez le fichier du relevé.';

            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['file' => $message]], 422);
        }

        // `tmp_name` est le chemin attribué par PHP au fichier reçu, jamais une valeur du client ; le nom d'origine ne sert qu'à l'affichage.
        /**
         * @psalm-taint-escape file
         * @psalm-taint-escape ssrf
         */
        $temporaryPath = $file['tmp_name'];
        $statement = $this->statements->deposit($admin, $yearId, $request->allBody(), $temporaryPath, $file['name'], $file['size']);

        return new JsonResponse(['id' => $statement->id, 'url' => '/admin/accounting/statements?year=' . $yearId], 201);
    }
}
