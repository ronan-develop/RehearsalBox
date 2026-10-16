<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Accounting\Entity\Direction;
use App\Accounting\Exception\AccountingValidationException;
use PHPUnit\Framework\Attributes\Test;

/** #362 : une erreur se corrige par une écriture inverse liée à l'originale, jamais en réécrivant l'écriture ; un exercice clos ne bouge pas. */
#[\PHPUnit\Framework\Attributes\Group('db')]
final class EntryReversalTest extends AccountingTestCase
{
    /** @return array{occurredOn: string, reason: string} */
    private function reversal(string $date = '2025-11-06'): array
    {
        return ['reason' => 'Erreur de saisie', 'occurredOn' => $date];
    }

    #[Test]
    public function testAReversalIsAnInverseLinkedEntryAndTheOriginalIsUntouched(): void
    {
        $id = $this->openYear();
        $original = $this->expense($id, '30,00');

        $reversal = $this->entries->reverse($this->admin, $original->id, $this->reversal(), $this->clock);

        self::assertSame($original->id, $reversal->reversesEntryId);
        self::assertSame(Direction::Recette, $reversal->direction, 'sens inverse');
        self::assertSame(3000, $reversal->amountCents);
        self::assertSame($original->category, $reversal->category, 'la catégorie de l\'original reste');
        self::assertSame($original->paymentMethod, $reversal->paymentMethod);
        self::assertSame(2, $reversal->number);
        self::assertSame('Erreur de saisie', $reversal->note);
        self::assertStringContainsString('n° 1', $reversal->label);
        $list = $this->entries->list($this->admin, $id);
        self::assertEquals($original, $list[0], 'l\'original est strictement identique');
        $summary = $this->years->summary($this->admin, $id);
        self::assertSame(10000, $summary['balanceCents'], 'solde revenu à sa valeur d\'avant');
        self::assertSame(0, $summary['totals']->netByCategory['loyer'], 'net de la catégorie à zéro');
    }

    #[Test]
    public function testAnEntryIsReversedOnlyOnceAndAReversalCannotBeReversed(): void
    {
        $id = $this->openYear();
        $original = $this->record($id);
        $reversal = $this->entries->reverse($this->admin, $original->id, $this->reversal(), $this->clock);

        foreach ([$original->id, $reversal->id] as $target) {
            try {
                $this->entries->reverse($this->admin, $target, $this->reversal(), $this->clock);
                self::fail('Contre-passation refusée attendue');
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('entry', $e->fields());
            }
        }
        self::assertCount(2, $this->entries->list($this->admin, $id), 'rien n\'a été ajouté');
        self::assertSame(3, $this->record($id)->number, 'les refus n\'ont brûlé aucun numéro');
    }

    #[Test]
    public function testAReversalNeedsAReasonAndAnOpenYearAtItsDate(): void
    {
        $id = $this->openYear();
        $original = $this->record($id);

        foreach ([['reason' => '', 'occurredOn' => '2025-11-06'], ['reason' => 'ok', 'occurredOn' => '2024-01-01'], ['reason' => 'ok', 'occurredOn' => '2027-01-01']] as $bad) {
            try {
                $this->entries->reverse($this->admin, $original->id, $bad, $this->clock);
                self::fail('Refus attendu');
            } catch (AccountingValidationException $e) {
                self::assertNotSame([], $e->fields());
            }
        }
        self::assertCount(1, $this->entries->list($this->admin, $id));
    }

    #[Test]
    public function testAnEntryOfAClosedYearIsReversedInTheOpenYearAndTheClosedYearNeverMoves(): void
    {
        $first = $this->openYear();
        $original = $this->record($first);
        $this->years->close($this->admin, $first, 0, $this->clock);
        $closedBefore = $this->years->get($this->admin, $first);
        $second = $this->openYear('2026-2027', '2026-10-01', '2027-09-30');

        $reversal = $this->entries->reverse($this->admin, $original->id, $this->reversal('2026-10-15'), $this->clock);

        self::assertSame($second, $reversal->fiscalYearId, 'datée dans l\'exercice ouvert');
        self::assertSame($original->id, $reversal->reversesEntryId);
        self::assertEquals($closedBefore, $this->years->get($this->admin, $first), 'l\'exercice clos n\'a pas changé');
        self::assertCount(1, $this->entries->list($this->admin, $first));
        self::assertSame(12000 - 2000, $this->years->summary($this->admin, $second)['balanceCents']);
    }

    #[Test]
    public function testAReversalOfAClosedYearCannotBeDatedInsideThatClosedYear(): void
    {
        $first = $this->openYear();
        $original = $this->record($first);
        $this->years->close($this->admin, $first, 0, $this->clock);
        $this->openYear('2026-2027', '2026-10-01', '2027-09-30');

        $this->expectException(AccountingValidationException::class);
        $this->entries->reverse($this->admin, $original->id, $this->reversal('2026-05-01'), $this->clock);
    }

    #[Test]
    public function testTheReversalLogCarriesIdsOnly(): void
    {
        $id = $this->openYear();
        $original = $this->record($id, ['label' => 'Libellé confidentiel']);
        $this->entries->reverse($this->admin, $original->id, ['reason' => 'Motif confidentiel', 'occurredOn' => '2025-11-06'], $this->clock);

        $log = implode("\n", $this->logged);
        self::assertStringContainsString('"entry":' . $original->id, $log);
        self::assertStringNotContainsString('confidentiel', $log);
    }
}
