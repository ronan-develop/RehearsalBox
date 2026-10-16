<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Association\Exception\AssociationTextChangedException;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\EntryNotFoundException;
use App\Accounting\Exception\FiscalYearChangedException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Association\Exception\AssociationBoardNotFoundException;
use App\Association\Exception\AssociationBoardValidationException;
use App\Association\Exception\AssociationTextNotFoundException;
use App\Association\Exception\AssociationTextValidationException;
use App\Assembly\Exception\AssemblyLockedException;
use App\Assembly\Exception\AssemblyNotFoundException;
use App\Assembly\Exception\AssemblyValidationException;
use App\Http\ExceptionTranslator;
use App\Group\Exception\DuplicateGroupNameException;
use App\Planning\Exception\DuplicateOccurrenceException;
use App\Planning\Exception\AvailabilityValidationException;
use App\Messaging\Exception\ConversationRateLimitException;
use App\Security\Exception\RateLimitedException;
use App\Messaging\Exception\ConversationValidationException;
use App\Planning\Exception\FreeSlotBookingConflictException;
use App\Group\Exception\GroupValidationException;
use App\Account\Exception\InvalidEmailChangeException;
use App\Account\Exception\InvalidResetTokenException;
use App\Group\Exception\InvalidUploadException;
use App\Planning\Exception\RequestAlreadyRespondedException;
use App\Planning\Exception\RequestChangedException;
use App\Group\Exception\StorageQuotaExceededException;
use App\Account\Exception\UserAdminRuleException;
use App\Account\Exception\UserNotFoundException;
use App\Account\Exception\UserValidationException;
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
        yield 'groupe invalide' => [new GroupValidationException(['name' => 'Nom requis.', 'colorHex' => 'Couleur invalide.']), 422, ['error' => 'Nom requis. Couleur invalide.', 'fields' => ['name' => 'Nom requis.', 'colorHex' => 'Couleur invalide.']]];
        yield 'demande de créneau invalide' => [new AvailabilityValidationException(['occurrenceDate' => 'Passé.']), 422, ['error' => 'Validation échouée', 'fields' => ['occurrenceDate' => 'Passé.']]];
        yield 'trop de messages' => [new ConversationRateLimitException('Trop de messages envoyés.'), 423, ['error' => 'Trop de messages envoyés.']];
        yield 'trop d\'actions' => [new RateLimitedException('Trop de recherches.', 42), 423, ['error' => 'Trop de recherches.']];
        yield 'jeton de réinitialisation' => [new InvalidResetTokenException(), 422, ['error' => (new InvalidResetTokenException())->getMessage()]];
        yield 'changement d\'adresse' => [new InvalidEmailChangeException(), 422, ['error' => (new InvalidEmailChangeException())->getMessage()]];
        yield 'fichier refusé' => [new InvalidUploadException('Type non autorisé.'), 422, ['error' => 'Type non autorisé.']];
        yield 'règle d\'administration' => [new UserAdminRuleException('Dernier administrateur.'), 422, ['error' => 'Dernier administrateur.']];
        yield 'compte rendu incomplet' => [new AssemblyValidationException(['location' => 'Lieu requis.']), 422, ['error' => 'Validation échouée', 'fields' => ['location' => 'Lieu requis.']]];
        yield 'compte rendu validé ou modifié' => [new AssemblyLockedException(), 409, ['error' => (new AssemblyLockedException())->getMessage()]];
        yield 'texte de l\'association invalide' => [new AssociationTextValidationException(['title' => 'Un texte porte déjà ce titre.']), 422, ['error' => 'Validation échouée', 'fields' => ['title' => 'Un texte porte déjà ce titre.']]];
        yield 'texte modifié entre-temps' => [new AssociationTextChangedException(), 409, ['error' => (new AssociationTextChangedException())->getMessage()]];
        yield 'poste du bureau refusé' => [new AssociationBoardValidationException(['userId' => 'Choisissez un compte actif.']), 422, ['error' => 'Validation échouée', 'fields' => ['userId' => 'Choisissez un compte actif.']]];
        yield 'poste du bureau introuvable' => [new AssociationBoardNotFoundException(), 404, ['error' => 'Poste introuvable.']];
        yield 'écriture comptable refusée' => [new AccountingValidationException(['amount' => 'Montant invalide.']), 422, ['error' => 'Validation échouée', 'fields' => ['amount' => 'Montant invalide.']]];
        yield 'exercice clos' => [new FiscalYearLockedException(), 409, ['error' => 'Cet exercice n\'est plus ouvert : aucune écriture n\'y est possible.']];
        yield 'exercice modifié entre-temps' => [new FiscalYearChangedException(), 409, ['error' => 'Cet exercice a été modifié entre-temps : rechargez la page.']];
        yield 'exercice introuvable' => [new FiscalYearNotFoundException(), 404, ['error' => 'Exercice introuvable.']];
        yield 'écriture introuvable' => [new EntryNotFoundException(), 404, ['error' => 'Écriture introuvable.']];
        yield 'texte introuvable' => [new AssociationTextNotFoundException(), 404, ['error' => 'Texte introuvable.']];
        yield 'compte rendu introuvable' => [new AssemblyNotFoundException(), 404, ['error' => 'Compte rendu introuvable.']];
        yield 'demande déjà traitée' => [new RequestAlreadyRespondedException('Déjà traitée.'), 409, ['error' => 'Déjà traitée.']];
        yield 'demande modifiée entre-temps' => [new RequestChangedException(), 409, ['error' => (new RequestChangedException())->getMessage()]];
        yield 'nom de groupe déjà pris' => [new DuplicateGroupNameException(), 409, ['error' => (new DuplicateGroupNameException())->getMessage()]];
        yield 'date déjà demandée' => [new DuplicateOccurrenceException(), 409, ['error' => (new DuplicateOccurrenceException())->getMessage()]];
        yield 'réservation en conflit' => [new FreeSlotBookingConflictException('Plage déjà réservée.'), 409, ['error' => 'Plage déjà réservée.']];
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

    #[Test]
    public function testARateLimitedActionTellsHowLongToWait(): void
    {
        $response = (new ExceptionTranslator())->translate(new RateLimitedException('Trop de recherches.', 42));

        self::assertSame('42', $response?->headers()['Retry-After']);
    }
}
