<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Presenter;

use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Presenter\MoneyFormatter;
use App\Accounting\Presenter\YearReportView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : rapport financier d'un exercice (page imprimable et texte repris dans le compte rendu d'AG) : chiffres exacts, texte brut, état de l'exercice dit clairement. */
final class YearReportViewTest extends TestCase
{
    private function year(FiscalYearStatus $status = FiscalYearStatus::Cloture, ?\DateTimeImmutable $approved = null): FiscalYear
    {
        return new FiscalYear(1, '2025-2026', new \DateTimeImmutable('2025-10-01'), new \DateTimeImmutable('2026-09-30'), $status, 10000, $status === FiscalYearStatus::Ouvert ? null : 9000, 3, $approved, 2);
    }

    private function totals(): LedgerTotals
    {
        return new LedgerTotals(2000, 3000, ['cotisations' => 2000, 'loyer' => -3000]);
    }

    private function view(): YearReportView
    {
        return new YearReportView(new MoneyFormatter());
    }

    #[Test]
    public function testTheFiguresAreExactAndSplitByDirection(): void
    {
        $report = $this->view()->build($this->year(), $this->totals(), [], [], 'Association');

        self::assertSame("100,00\u{a0}€", $report['opening']);
        self::assertSame("20,00\u{a0}€", $report['recettes']);
        self::assertSame("30,00\u{a0}€", $report['depenses']);
        self::assertSame("90,00\u{a0}€", $report['closing']);
        self::assertSame([['label' => 'Cotisations', 'amount' => "20,00\u{a0}€"]], $report['recetteLines']);
        self::assertSame([['label' => 'Loyer', 'amount' => "30,00\u{a0}€"]], $report['depenseLines']);
        self::assertSame('Association', $report['associationName']);
    }

    #[Test]
    public function testAnOpenYearIsAnnouncedAsProvisionalAndAnApprovedOneNamesTheDate(): void
    {
        self::assertStringContainsString('provisoires', (string) $this->view()->build($this->year(FiscalYearStatus::Ouvert), $this->totals(), [], [], '')['banner']);
        self::assertNull($this->view()->build($this->year(), $this->totals(), [], [], '')['banner']);
        $approved = $this->view()->build($this->year(FiscalYearStatus::Approuve, new \DateTimeImmutable('2026-12-12')), $this->totals(), [], [], '');
        self::assertStringContainsString('12/12/2026', $approved['statusLine']);
    }

    #[Test]
    public function testTheJournalLinesCarrySignedAmountsAndTheMemberName(): void
    {
        $entry = new AccountingEntry(7, 1, 1, new \DateTimeImmutable('2025-11-05'), 'Cotisation', 2000, Direction::Recette, EntryCategory::Cotisations, PaymentMethod::Virement, null, null, null, 5, new \DateTimeImmutable('2025-11-01'));

        $report = $this->view()->build($this->year(), $this->totals(), [$entry], [5 => 'Jeanne'], '');

        self::assertSame('Cotisation de Jeanne pour novembre 2025', $report['entries'][0]['detail']);
        self::assertSame("+20,00\u{a0}€", $report['entries'][0]['amount']);
        self::assertSame('05/11/2025', $report['entries'][0]['date']);
    }

    #[Test]
    public function testTheFiguresTextIsPlainTextForTheAssemblyMinutes(): void
    {
        $text = $this->view()->figuresText($this->year(), $this->totals());

        self::assertStringContainsString('exercice 2025-2026', $text);
        self::assertStringContainsString("Solde d'ouverture : 100,00\u{a0}€", $text);
        self::assertStringContainsString("Recettes : 20,00\u{a0}€", $text);
        self::assertStringContainsString("Dépenses : 30,00\u{a0}€", $text);
        self::assertStringContainsString("Solde final : 90,00\u{a0}€", $text);
        self::assertStringContainsString("- Cotisations : 20,00\u{a0}€", $text);
        self::assertStringContainsString("- Loyer : 30,00\u{a0}€", $text);
        self::assertStringNotContainsString('<', $text);
    }

    #[Test]
    public function testAnOpenYearFiguresTextSaysTheBalanceIsProvisional(): void
    {
        $text = $this->view()->figuresText($this->year(FiscalYearStatus::Ouvert), $this->totals());

        self::assertStringContainsString('provisoire', $text);
        self::assertStringContainsString("Solde actuel : 90,00\u{a0}€", $text);
    }
}
