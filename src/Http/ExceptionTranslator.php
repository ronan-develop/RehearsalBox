<?php

declare(strict_types=1);

namespace App\Http;

use App\Repository\Exception\DuplicateOccurrenceException;
use App\Service\Exception\AvailabilityValidationException;
use App\Service\Exception\ConversationRateLimitException;
use App\Service\Exception\ConversationValidationException;
use App\Service\Exception\InvalidEmailChangeException;
use App\Service\Exception\InvalidResetTokenException;
use App\Service\Exception\InvalidUploadException;
use App\Service\Exception\RequestAlreadyRespondedException;
use App\Service\Exception\RequestChangedException;
use App\Service\Exception\StorageQuotaExceededException;
use App\Service\Exception\UserAdminRuleException;
use App\Service\Exception\UserNotFoundException;
use App\Service\Exception\UserValidationException;

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
            $e instanceof ConversationValidationException => new JsonResponse(['error' => $e->getMessage(), 'fields' => $e->fields()], 422),
            $e instanceof UserValidationException,
            $e instanceof AvailabilityValidationException => new JsonResponse(['error' => 'Validation échouée', 'fields' => $e->fields()], 422),
            $e instanceof ConversationRateLimitException => new JsonResponse(['error' => $e->getMessage()], 429),
            $e instanceof InvalidResetTokenException,
            $e instanceof InvalidEmailChangeException,
            $e instanceof InvalidUploadException,
            $e instanceof UserAdminRuleException => new JsonResponse(['error' => $e->getMessage()], 422),
            $e instanceof RequestAlreadyRespondedException,
            $e instanceof RequestChangedException,
            $e instanceof DuplicateOccurrenceException,
            $e instanceof StorageQuotaExceededException => new JsonResponse(['error' => $e->getMessage()], 409),
            // Le message nomme l'identifiant demandé : il n'est jamais renvoyé.
            $e instanceof UserNotFoundException => new JsonResponse(['error' => 'Utilisateur introuvable.'], 404),
            default => null,
        };
    }
}
