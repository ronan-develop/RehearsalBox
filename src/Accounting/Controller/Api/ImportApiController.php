<?php

declare(strict_types=1);

namespace App\Accounting\Controller\Api;

use App\Account\Entity\UserRole;
use App\Account\Service\Throttle\UserActionLimit;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Import\ImportService;
use App\Accounting\Import\Parse\ImportLimits;
use App\Accounting\Import\Parse\ParsedLine;
use App\Accounting\Import\Rules\RuleService;
use App\Accounting\Import\Validate\BatchValidationService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * API de l'import du CSV de la banque (#362), administrateurs uniquement (garde ici ET dans les services), CSRF exigé par le noyau. Le fichier
 * est lu en mémoire (1 Mo au plus), jamais conservé, jamais exécuté ; l'aperçu n'écrit rien. À la validation, le client ne donne que ce que la
 * personne a relu (libellé, catégorie, moyen, pièce, note, adhérent, mois, échéance, règle) : la date, le montant et le sens viennent de la
 * ligne en attente. Identifiants strictement numériques dans l'adresse ; erreurs métier traduites par ExceptionTranslator.
 */
final class ImportApiController
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly BatchValidationService $validation,
        private readonly RuleService $rules,
        private readonly AuthGuard $authGuard,
        private readonly ?UserActionLimit $uploadLimit = null,
    ) {
    }

    public function preview(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->uploadLimit?->hit($admin->id());
        $result = $this->imports->preview($admin, $this->content($request), $request->allBody());

        return new JsonResponse([
            'total' => count($result->lines),
            'lines' => array_map(static fn (ParsedLine $l): array => [
                'record' => $l->recordNumber,
                'date' => $l->date->format('d/m/Y'),
                'label' => $l->label,
                'cents' => $l->amountCents,
                'direction' => $l->direction->value,
            ], array_slice($result->lines, 0, ImportLimits::PREVIEW_LINES)),
            'errors' => $result->errors,
        ]);
    }

    public function stage(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->uploadLimit?->hit($admin->id());
        $summary = $this->imports->stage($admin, $this->content($request), $request->allBody());

        return new JsonResponse($summary + ['url' => '/admin/accounting/imports'], 201);
    }

    public function discard(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->imports->discard($admin, StrictId::orDenied($id));

        return new JsonResponse(['url' => '/admin/accounting/imports']);
    }

    public function validate(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $rows = $request->body('rows');
        $result = $this->validation->validate($admin, is_array($rows) ? array_values($rows) : []);

        return new JsonResponse($result + ['url' => '/admin/accounting/imports']);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->rules->retarget($admin, StrictId::orDenied($id), $request->allBody());

        return new JsonResponse(['url' => '/admin/accounting/rules']);
    }

    public function deleteRule(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->rules->delete($admin, StrictId::orDenied($id));

        return new JsonResponse(['url' => '/admin/accounting/rules']);
    }

    /** Le contenu du fichier envoyé : lu au plus 1 Mo + 1 octet (le service refuse ce qui dépasse), jamais écrit ailleurs. */
    private function content(Request $request): string
    {
        $file = $request->file('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            $tooBig = $file !== null && in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
            throw new AccountingValidationException(['file' => $tooBig ? 'Le fichier dépasse 1 Mo.' : 'Choisissez le fichier CSV de la banque.']);
        }
        /**
         * `tmp_name` est le chemin attribué par PHP au fichier reçu, jamais une valeur du client.
         *
         * @psalm-taint-escape file
         * @psalm-taint-escape ssrf
         */
        $temporaryPath = $file['tmp_name'];

        return (string) file_get_contents($temporaryPath, false, null, 0, ImportLimits::MAX_BYTES + 1);
    }
}
