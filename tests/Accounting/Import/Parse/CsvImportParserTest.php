<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Import\Parse;

use App\Accounting\Entity\Direction;
use App\Accounting\Import\Parse\CsvImportParser;
use App\Accounting\Import\Parse\CsvProfile;
use App\Accounting\Import\Parse\ImportLimits;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #362 livraison 7 : lecture d'un CSV de banque d'après un profil de colonnes choisi à l'import. Fichiers INVENTÉS dans plusieurs formats
 * (séparateur, encodage, date, montant signé ou débit/crédit) ; lignes mal formées signalées une à une ; rien n'est jamais exécuté.
 */
final class CsvImportParserTest extends TestCase
{
    private function signed(string $delimiter = ';', string $encoding = 'UTF-8', int $skip = 1, string $decimal = ',', string $dateFormat = 'd/m/Y'): CsvProfile
    {
        return new CsvProfile($delimiter, $encoding, $skip, 1, 2, CsvProfile::SIGNED, 3, 0, 0, $decimal, $dateFormat);
    }

    private function split(): CsvProfile
    {
        return new CsvProfile(';', 'UTF-8', 1, 1, 2, CsvProfile::SPLIT, 0, 3, 4, ',', 'd/m/Y');
    }

    #[Test]
    public function testASignedFrenchFileGivesDirectionsAndCentsWithoutAnyFloat(): void
    {
        $csv = "Date;Libellé;Montant\n05/11/2025;VIR COTISATION;20,00\n06/11/2025;PRLV LOYER;-166,00\n07/11/2025;FRAIS;-0,10\n";

        $result = (new CsvImportParser())->parse($csv, $this->signed());

        self::assertSame([], $result->errors);
        self::assertCount(3, $result->lines);
        [$a, $b, $c] = $result->lines;
        self::assertSame([2000, Direction::Recette, '2025-11-05', 'VIR COTISATION'], [$a->amountCents, $a->direction, $a->date->format('Y-m-d'), $a->label]);
        self::assertSame([16600, Direction::Depense], [$b->amountCents, $b->direction]);
        self::assertSame(10, $c->amountCents);
        self::assertSame(2, $a->recordNumber, 'numéro de l\'enregistrement dans le fichier, en-tête compris');
    }

    #[Test]
    public function testADebitAndCreditFileWithCommaDelimiterAndPointDecimalsIsRead(): void
    {
        $csv = "date,label,debit,credit\n2025-11-05,Loyer,166.00,\n2025-11-06,Don,,50.5\n";
        $profile = new CsvProfile(',', 'UTF-8', 1, 1, 2, CsvProfile::SPLIT, 0, 3, 4, '.', 'Y-m-d');

        $lines = (new CsvImportParser())->parse($csv, $profile)->lines;

        self::assertSame([[16600, Direction::Depense], [5050, Direction::Recette]], array_map(static fn ($l): array => [$l->amountCents, $l->direction], $lines));
    }

    #[Test]
    public function testThousandsSeparatorsAndSpacesInAmountsAreAccepted(): void
    {
        $csv = "d;l;m\n05/11/2025;Subvention;1 234,50\n06/11/2025;Autre;\"1\u{202f}000,00\"\n07/11/2025;X;1.234,56\n";

        $lines = (new CsvImportParser())->parse($csv, $this->signed())->lines;

        self::assertSame([123450, 100000, 123456], array_map(static fn ($l): int => $l->amountCents, $lines));
    }

    #[Test]
    public function testAnIso88591FileWithAccentsIsConvertedAndABomIsIgnored(): void
    {
        $latin1 = mb_convert_encoding("Date;Libellé;Montant\n05/11/2025;Électricité EDF;-63,40\n", 'ISO-8859-1', 'UTF-8');

        $lines = (new CsvImportParser())->parse($latin1, $this->signed(encoding: 'ISO-8859-1'))->lines;
        self::assertSame('Électricité EDF', $lines[0]->label);

        $bom = (new CsvImportParser())->parse("\xEF\xBB\xBFDate;Libellé;Montant\n05/11/2025;Test;1,00\n", $this->signed())->lines;
        self::assertCount(1, $bom);
    }

    #[Test]
    public function testBadRecordsAreReportedOneByOneAndTheGoodOnesAreKept(): void
    {
        $csv = "Date;Libellé;Montant\n31/02/2025;Date impossible;1,00\n05/11/2025;;2,00\n05/11/2025;Montant;abc\n05/11/2025;Zéro;0,00\n05/11/2025;Trop;99999999999,00\n05/11/2025;Bon;3,00\n05/11/2025;Court\n";

        $result = (new CsvImportParser())->parse($csv, $this->signed());

        self::assertCount(1, $result->lines);
        self::assertSame('Bon', $result->lines[0]->label);
        self::assertSame([2, 3, 4, 5, 6, 8], array_keys($result->errors));
        foreach ($result->errors as $reason) {
            self::assertNotSame('', $reason);
            self::assertStringNotContainsString('abc', $reason, 'la raison ne répète jamais le contenu');
        }
    }

    #[Test]
    public function testDebitAndCreditBothFilledOrBothEmptyIsRefused(): void
    {
        $csv = "d;l;debit;credit\n05/11/2025;Deux;1,00;2,00\n05/11/2025;Aucun;;\n05/11/2025;Bon;;4,00\n";

        $result = (new CsvImportParser())->parse($csv, $this->split());

        self::assertCount(1, $result->lines);
        self::assertSame([2, 3], array_keys($result->errors));
    }

    #[Test]
    public function testFormulasAreKeptAsPlainTextAndControlCharactersAreRemoved(): void
    {
        $csv = "Date;Libellé;Montant\n05/11/2025;=HYPERLINK(\"http://x.test\");1,00\n05/11/2025;\"Vir\x07ement\u{202E}\";2,00\n";

        $lines = (new CsvImportParser())->parse($csv, $this->signed())->lines;

        self::assertSame('=HYPERLINK("http://x.test")', $lines[0]->label, 'texte brut : le CSV de sortie le neutralisera');
        self::assertSame('Virement', $lines[1]->label);
    }

    #[Test]
    public function testAQuotedFieldWithNewlineAndDelimiterIsOneRecord(): void
    {
        $csv = "Date;Libellé;Montant\n05/11/2025;\"Ligne;un\nligne deux\";5,00\n";

        $lines = (new CsvImportParser())->parse($csv, $this->signed())->lines;

        self::assertCount(1, $lines);
        self::assertSame('Ligne;un ligne deux', $lines[0]->label);
    }

    #[Test]
    public function testALongLabelIsCutAndEmptyLinesAreIgnored(): void
    {
        $csv = "Date;Libellé;Montant\n\n05/11/2025;" . str_repeat('é', 400) . ";1,00\n\n";

        $result = (new CsvImportParser())->parse($csv, $this->signed());

        self::assertCount(1, $result->lines);
        self::assertSame(ImportLimits::LABEL_MAX, mb_strlen($result->lines[0]->label));
    }

    #[Test]
    public function testTheNumberOfRecordsIsCapped(): void
    {
        $csv = "Date;Libellé;Montant\n" . str_repeat("05/11/2025;L;1,00\n", ImportLimits::MAX_RECORDS + 5);

        $result = (new CsvImportParser())->parse($csv, $this->signed());

        self::assertCount(ImportLimits::MAX_RECORDS, $result->lines);
        self::assertArrayHasKey(0, $result->errors, 'une alerte globale signale la coupure');
    }

    #[Test]
    public function testBinaryOrBrokenContentIsRefusedWithoutAnyLine(): void
    {
        $parser = new CsvImportParser();

        self::assertSame([], $parser->parse("%PDF-1.4\0\0\0binaire", $this->signed())->lines);
        self::assertArrayHasKey(0, $parser->parse("%PDF-1.4\0\0\0binaire", $this->signed())->errors);
        self::assertSame([], $parser->parse("Date;Libellé;Montant\n05/11/2025;\xC3\x28;1,00\n", $this->signed())->lines, 'UTF-8 invalide');
        self::assertSame([], $parser->parse('', $this->signed())->lines);
    }

    #[Test]
    public function testAColumnBeyondTheRecordIsAnErrorNotAnException(): void
    {
        $profile = new CsvProfile(';', 'UTF-8', 0, 1, 2, CsvProfile::SIGNED, 9, 0, 0, ',', 'd/m/Y');

        $result = (new CsvImportParser())->parse("05/11/2025;Libellé;1,00\n", $profile);

        self::assertSame([], $result->lines);
        self::assertSame([1], array_keys($result->errors));
    }

    #[Test]
    public function testRandomRecordsNeverThrowAndNeverYieldANonPositiveAmountOrAnEmptyLabel(): void
    {
        mt_srand(362);
        $parser = new CsvImportParser();
        $junk = ['=', '+', '-', '@', ' ', '0', '1', '9', '.', ',', '"', "\t", "\xC3", 'a', 'é', '€', '(', ')'];
        $random = static function (array $pool, int $max) {
            $text = '';
            for ($j = 0, $n = mt_rand(0, $max); $j < $n; ++$j) {
                $text .= $pool[mt_rand(0, count($pool) - 1)];
            }

            return $text;
        };
        $kept = 0;
        for ($i = 0; $i < 600; ++$i) {
            $date = mt_rand(0, 3) === 0 ? $random($junk, 10) : sprintf('%02d/%02d/%d', mt_rand(1, 31), mt_rand(1, 12), mt_rand(2020, 2026));
            $csv = "d;l;m\n" . $date . ';' . $random($junk, 30) . ';' . (mt_rand(0, 2) === 0 ? $random($junk, 12) : mt_rand(-99999, 99999) . ',' . mt_rand(0, 99)) . "\n";
            $result = $parser->parse($csv, $this->signed());
            foreach ($result->lines as $line) {
                ++$kept;
                self::assertGreaterThan(0, $line->amountCents);
                self::assertNotSame('', $line->label);
                self::assertLessThanOrEqual(ImportLimits::LABEL_MAX, mb_strlen($line->label));
                self::assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $line->label);
            }
        }
        self::assertGreaterThan(20, $kept, 'le générateur produit de vraies lignes');
    }
}
