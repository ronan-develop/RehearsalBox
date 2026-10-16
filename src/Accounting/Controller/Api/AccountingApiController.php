<?php

declare(strict_types=1);

namespace App\Accounting\Controller\Api;

use App\Account\Entity\UserRole;
use App\Accounting\Service\EntryService;
use App\Accounting\Service\FiscalYearService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * API de la comptabilité (#362), administrateurs uniquement : garde de rôle ici ET dans les services. Le jeton CSRF est exigé par le
 * noyau pour toute écriture. Les identifiants viennent de l'adresse et doivent être strictement numériques (sinon refus comme un accès
 * interdit, sans indice sur l'existence) ; le corps ne fixe jamais le numéro, l'exercice, l'auteur ni l'état. Les erreurs métier (422 par
 * champ, 409 exercice clos, 404) sont traduites par ExceptionTranslator.
 */
final class AccountingApiController
{
    public function __construct(
        private readonly FiscalYearService $years,
        private readonly EntryService $entries,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function createYear(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $id = $this->years->create($admin, $request->allBody());

        return new JsonResponse(['id' => $id, 'url' => '/admin/accounting?year=' . $id], 201);
    }

    public function recordEntry(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $yearId = StrictId::orDenied($id);
        $entry = $this->entries->record($admin, $yearId, $request->allBody());

        return new JsonResponse(['id' => $entry->id, 'number' => $entry->number, 'url' => '/admin/accounting?year=' . $yearId], 201);
    }

    public function reverseEntry(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $reversal = $this->entries->reverse($admin, StrictId::orDenied($id), $request->allBody());

        return new JsonResponse(['id' => $reversal->id, 'number' => $reversal->number, 'url' => '/admin/accounting?year=' . $reversal->fiscalYearId], 201);
    }
}
