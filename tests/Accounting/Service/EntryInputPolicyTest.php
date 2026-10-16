<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Service\AccountingAmountParser;
use App\Accounting\Service\EntryInputPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : une écriture saisie est validée AVANT la base, toutes les erreurs remontées ensemble par champ. */
final class EntryInputPolicyTest extends TestCase
{
    private function policy(): EntryInputPolicy
    {
        return new EntryInputPolicy(new AccountingAmountParser());
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'occurredOn' => '2026-10-05',
            'label' => 'Cotisation d\'octobre',
            'amount' => '20,00',
            'direction' => 'recette',
            'category' => 'cotisations',
            'paymentMethod' => 'virement',
            'voucherRef' => '',
            'note' => '',
        ];
    }

    #[Test]
    public function testAValidEntryBecomesATypedDraftWithExactCents(): void
    {
        $draft = $this->policy()->parse($this->input(['label' => '  Cotisation  ', 'voucherRef' => ' RELEVE-10 ']));

        self::assertSame('2026-10-05', $draft->occurredOn->format('Y-m-d'));
        self::assertSame('Cotisation', $draft->label);
        self::assertSame(2000, $draft->amountCents);
        self::assertSame(Direction::Recette, $draft->direction);
        self::assertSame(EntryCategory::Cotisations, $draft->category);
        self::assertSame(PaymentMethod::Virement, $draft->paymentMethod);
        self::assertSame('RELEVE-10', $draft->voucherRef);
        self::assertNull($draft->note);
    }

    #[Test]
    public function testAnExpenseNeedsAVoucherReferenceButAReceiptDoesNot(): void
    {
        $expense = $this->input(['direction' => 'depense', 'category' => 'loyer', 'voucherRef' => '']);

        try {
            $this->policy()->parse($expense);
            self::fail('Une dépense sans pièce doit être refusée');
        } catch (AccountingValidationException $e) {
            self::assertSame(['voucherRef'], array_keys($e->fields()));
        }
        self::assertSame('F-2026-10', $this->policy()->parse(array_merge($expense, ['voucherRef' => 'F-2026-10']))->voucherRef);
        self::assertNull($this->policy()->parse($this->input(['voucherRef' => '']))->voucherRef, 'une recette sans pièce est permise');
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refused(): iterable
    {
        yield 'date absente' => [['occurredOn' => ''], 'occurredOn'];
        yield 'date impossible' => [['occurredOn' => '2026-02-30'], 'occurredOn'];
        yield 'date au mauvais format' => [['occurredOn' => '05/10/2026'], 'occurredOn'];
        yield 'date nulle' => [['occurredOn' => '0000-00-00'], 'occurredOn'];
        yield 'date non texte' => [['occurredOn' => 20261005], 'occurredOn'];
        yield 'libellé vide' => [['label' => '   '], 'label'];
        yield 'libellé trop long' => [['label' => str_repeat('a', 151)], 'label'];
        yield 'libellé sur deux lignes' => [['label' => "a\nb"], 'label'];
        yield 'libellé avec caractère de contrôle' => [['label' => "a\x07b"], 'label'];
        yield 'libellé non texte' => [['label' => ['x']], 'label'];
        yield 'montant nul' => [['amount' => '0'], 'amount'];
        yield 'montant négatif' => [['amount' => '-5'], 'amount'];
        yield 'montant à virgule flottante' => [['amount' => 12.5], 'amount'];
        yield 'montant absent' => [['amount' => null], 'amount'];
        yield 'sens inconnu' => [['direction' => 'don'], 'direction'];
        yield 'catégorie inconnue' => [['category' => 'cadeaux'], 'category'];
        yield 'catégorie de dépense pour une recette' => [['direction' => 'recette', 'category' => 'loyer'], 'category'];
        yield 'catégorie de recette pour une dépense' => [['direction' => 'depense', 'category' => 'cotisations', 'voucherRef' => 'F1'], 'category'];
        yield 'moyen de paiement inconnu' => [['paymentMethod' => 'bitcoin'], 'paymentMethod'];
        yield 'pièce trop longue' => [['voucherRef' => str_repeat('a', 101)], 'voucherRef'];
        yield 'pièce sur deux lignes' => [['voucherRef' => "a\nb"], 'voucherRef'];
        yield 'note trop longue' => [['note' => str_repeat('a', 256)], 'note'];
        yield 'note avec caractère de contrôle' => [['note' => "a\x00b"], 'note'];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('refused')]
    public function testEachBadFieldIsRefusedWithItsOwnMessage(array $override, string $field): void
    {
        try {
            $this->policy()->parse($this->input($override));
            self::fail("« {$field} » aurait dû être refusé");
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
    }

    #[Test]
    public function testEveryErrorIsReportedTogether(): void
    {
        try {
            $this->policy()->parse(['label' => '', 'amount' => 'abc']);
            self::fail('Saisie vide refusée');
        } catch (AccountingValidationException $e) {
            self::assertEqualsCanonicalizing(['occurredOn', 'label', 'amount', 'direction', 'category', 'paymentMethod'], array_keys($e->fields()));
        }
    }

    #[Test]
    public function testUnknownOrForgedFieldsAreIgnored(): void
    {
        $draft = $this->policy()->parse($this->input(['entryNumber' => 99, 'fiscalYearId' => 7, 'createdBy' => 1, 'status' => 'cloture', 'reversesEntryId' => 3]));

        self::assertSame(2000, $draft->amountCents);
    }

    #[Test]
    public function testAReasonIsRequiredToReverseAndBoundedToOneLine(): void
    {
        $policy = $this->policy();

        self::assertSame('Erreur de saisie', $policy->parseReversal(['reason' => ' Erreur de saisie ', 'occurredOn' => '2026-10-06'])[0]);
        foreach ([[], ['reason' => ''], ['reason' => "a\nb"], ['reason' => str_repeat('a', 256)], ['reason' => 'ok', 'occurredOn' => '2026-13-01']] as $bad) {
            try {
                $policy->parseReversal($bad + ['occurredOn' => '2026-10-06']);
                self::fail('Contre-passation refusée attendue');
            } catch (AccountingValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
