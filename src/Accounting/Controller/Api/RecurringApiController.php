<?php

declare(strict_types=1);

namespace App\Accounting\Controller\Api;

use App\Account\Entity\UserRole;
use App\Accounting\Service\Recurring\RecurringOccurrenceService;
use App\Accounting\Service\Recurring\RecurringTemplateService;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Security\AuthGuard;
use App\Support\StrictId;

/**
 * API des écritures récurrentes (#362), administrateurs uniquement : garde de rôle ici ET dans les services ; CSRF exigé par le noyau pour toute
 * écriture. Les identifiants viennent de l'adresse et doivent être strictement numériques (sinon refus comme un accès interdit). Une
 * confirmation ne reçoit du client que l'échéance, le montant, la date et la pièce : le libellé, le sens, la catégorie, le moyen, le
 * numéro et l'auteur viennent du modèle et du serveur. Erreurs métier traduites par ExceptionTranslator.
 */
final class RecurringApiController
{
    public function __construct(
        private readonly RecurringTemplateService $templates,
        private readonly RecurringOccurrenceService $occurrences,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);

        return new JsonResponse(['id' => $this->templates->create($admin, $request->allBody())], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $templateId = StrictId::orDenied($id);
        $this->templates->update($admin, $templateId, $request->allBody());

        return new JsonResponse(['id' => $templateId]);
    }

    public function confirm(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $entry = $this->occurrences->confirm($admin, StrictId::orDenied($id), $request->allBody(), new \DateTimeImmutable('today'));

        return new JsonResponse(['id' => $entry->id, 'number' => $entry->number, 'url' => '/admin/accounting/recurring'], 201);
    }

    public function ignore(Request $request, string $id): JsonResponse
    {
        $admin = $this->authGuard->requireRole(UserRole::Admin);
        $this->occurrences->ignore($admin, StrictId::orDenied($id), $request->allBody(), new \DateTimeImmutable('today'));

        return new JsonResponse(['url' => '/admin/accounting/recurring']);
    }
}
