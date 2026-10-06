<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ExceptionTranslator;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** La table unique « exception métier → réponse » (#220) : un statut et une forme par exception, jamais de trace. */
final class ExceptionTranslatorTest extends TestCase
{
    /** @return iterable<string, array{\Throwable, int, array<string, mixed>}> */
    public static function translations(): iterable
    {
        yield 'validation de conversation' => [new ConversationValidationException(['title' => 'Trop long.']), 422, ['error' => 'Trop long.', 'fields' => ['title' => 'Trop long.']]];
        yield 'validation de compte' => [new UserValidationException(['email' => 'Invalide.']), 422, ['error' => 'Validation échouée', 'fields' => ['email' => 'Invalide.']]];
        yield 'demande de créneau invalide' => [new AvailabilityValidationException(['occurrenceDate' => 'Passé.']), 422, ['error' => 'Validation échouée', 'fields' => ['occurrenceDate' => 'Passé.']]];
        yield 'trop de messages' => [new ConversationRateLimitException('Trop de messages envoyés.'), 429, ['error' => 'Trop de messages envoyés.']];
        yield 'jeton de réinitialisation' => [new InvalidResetTokenException(), 422, ['error' => (new InvalidResetTokenException())->getMessage()]];
        yield 'changement d\'adresse' => [new InvalidEmailChangeException(), 422, ['error' => (new InvalidEmailChangeException())->getMessage()]];
        yield 'fichier refusé' => [new InvalidUploadException('Type non autorisé.'), 422, ['error' => 'Type non autorisé.']];
        yield 'règle d\'administration' => [new UserAdminRuleException('Dernier administrateur.'), 422, ['error' => 'Dernier administrateur.']];
        yield 'demande déjà traitée' => [new RequestAlreadyRespondedException('Déjà traitée.'), 409, ['error' => 'Déjà traitée.']];
        yield 'demande modifiée entre-temps' => [new RequestChangedException(), 409, ['error' => (new RequestChangedException())->getMessage()]];
        yield 'date déjà demandée' => [new DuplicateOccurrenceException(), 409, ['error' => (new DuplicateOccurrenceException())->getMessage()]];
        yield 'quota dépassé' => [new StorageQuotaExceededException('Quota dépassé.'), 409, ['error' => 'Quota dépassé.']];
    }

    /** @param array<string, mixed> $expected */
    #[Test]
    #[DataProvider('translations')]
    public function testEachBusinessExceptionHasItsOwnStatusAndShape(\Throwable $exception, int $status, array $expected): void
    {
        $response = (new ExceptionTranslator())->translate($exception);

        self::assertNotNull($response);
        self::assertSame($status, $response->statusCode());
        self::assertSame($expected, json_decode($response->body(), true));
    }

    #[Test]
    public function testAnUnknownUserNeverRevealsItsIdentifier(): void
    {
        $response = (new ExceptionTranslator())->translate(new UserNotFoundException('Utilisateur 4242 introuvable.'));

        self::assertSame(404, $response->statusCode());
        self::assertSame(['error' => 'Utilisateur introuvable.'], json_decode($response->body(), true));
    }

    #[Test]
    public function testAnythingElseIsLeftToTheCallerAndNeverTranslated(): void
    {
        $translator = new ExceptionTranslator();

        self::assertNull($translator->translate(new \RuntimeException('boum')));
        self::assertNull($translator->translate(new \PDOException('SQLSTATE')));
        self::assertNull($translator->translate(new \InvalidArgumentException('contextuel : 404 ou 422 selon le contrôleur')));
    }
}
