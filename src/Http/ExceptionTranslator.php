<?php

declare(strict_types=1);

namespace App\Http;

use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\EntryNotFoundException;
use App\Accounting\Exception\FiscalYearChangedException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Exception\StatementNotFoundException;
use App\Association\Exception\AssociationBoardNotFoundException;
use App\Association\Exception\AssociationBoardValidationException;
use App\Association\Exception\AssociationTextChangedException;
use App\Association\Exception\AssociationTextNotFoundException;
use App\Association\Exception\AssociationTextValidationException;
use App\Assembly\Exception\AssemblyLockedException;
use App\Assembly\Exception\AssemblyNotFoundException;
use App\Assembly\Exception\AssemblyValidationException;
use App\Group\Exception\DuplicateGroupNameException;
use App\Planning\Exception\DuplicateOccurrenceException;
use App\Planning\Exception\AvailabilityValidationException;
use App\Messaging\Exception\ConversationRateLimitException;
use App\Security\Exception\RateLimitedException;
use App\Messaging\Exception\ConversationValidationException;
use App\Planning\Exception\FreeSlotBookingConflictException;
use App\Group\Exception\GroupValidationException;
use App\Group\Exception\LastGroupManagerException;
use App\Account\Exception\InvalidEmailChangeException;
use App\Account\Exception\InvalidResetTokenException;
use App\Group\Exception\InvalidUploadException;
use App\Planning\Exception\RequestAlreadyRespondedException;
use App\Planning\Exception\RequestChangedException;
use App\Group\Exception\StorageQuotaExceededException;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;

/**
 * La table unique « exception métier → réponse HTTP » (#220), utilisée par le Kernel : les contrôleurs n'ont plus qu'à laisser
 * remonter ces exceptions au lieu de répéter 33 `catch` identiques. Un statut et une forme par exception ; jamais de trace.
 * Ne traduit PAS les erreurs génériques (le Kernel répond alors par une 500) ni `InvalidArgumentException`, dont le statut
 * (404 ou 422) dépend du contrôleur.
 */
final class ExceptionTranslator
{
    /** @return JsonResponse|null null si l'exception n'est pas une erreur métier connue */
    public function translate(\Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ConversationValidationException,
            $e instanceof GroupValidationException => new JsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422),
            $e instanceof UserValidationException,
            $e instanceof AssemblyValidationException,
            $e instanceof AssociationTextValidationException,
            $e instanceof AssociationBoardValidationException,
            $e instanceof AccountingValidationException,
            $e instanceof AvailabilityValidationException => new JsonResponse(['error' => 'Validation échouée', 'fields' => $e->fields()], 422),
            $e instanceof ConversationRateLimitException => new JsonResponse(['error' => $e->getMessage()], Response::RATE_LIMITED),
            $e instanceof RateLimitedException => new JsonResponse(['error' => $e->getMessage()], Response::RATE_LIMITED, ['Retry-After' => (string) $e->retryAfterSeconds()]),
            $e instanceof InvalidResetTokenException,
            $e instanceof InvalidEmailChangeException,
            $e instanceof InvalidUploadException,
            $e instanceof UserAdminRuleException,
            $e instanceof LastGroupManagerException => new JsonResponse(['error' => $e->getMessage()], 422),
            $e instanceof FreeSlotBookingConflictException,
            $e instanceof AssemblyLockedException,
            $e instanceof AssociationTextChangedException,
            $e instanceof FiscalYearLockedException,
            $e instanceof FiscalYearChangedException,
            $e instanceof RequestAlreadyRespondedException,
            $e instanceof RequestChangedException,
            $e instanceof DuplicateOccurrenceException,
            $e instanceof DuplicateGroupNameException,
            $e instanceof StorageQuotaExceededException => new JsonResponse(['error' => $e->getMessage()], 409),
            // Le message nomme l'identifiant demandé : il n'est jamais renvoyé.
            $e instanceof UserNotFoundException => new JsonResponse(['error' => 'Utilisateur introuvable.'], 404),
            $e instanceof FiscalYearNotFoundException => new JsonResponse(['error' => 'Exercice introuvable.'], 404),
            $e instanceof EntryNotFoundException => new JsonResponse(['error' => 'Écriture introuvable.'], 404),
            $e instanceof StatementNotFoundException => new JsonResponse(['error' => 'Relevé introuvable.'], 404),
            $e instanceof AssociationBoardNotFoundException => new JsonResponse(['error' => 'Poste introuvable.'], 404),
            $e instanceof AssociationTextNotFoundException => new JsonResponse(['error' => 'Texte introuvable.'], 404),
            $e instanceof AssemblyNotFoundException => new JsonResponse(['error' => 'Compte rendu introuvable.'], 404),
            default => null,
        };
    }
}
