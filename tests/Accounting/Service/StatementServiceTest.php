<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Service;

use App\Account\Entity\User;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\StatementNotFoundException;
use App\Security\Exception\AccessDeniedException;
use PHPUnit\Framework\Attributes\Test;

/** #362 : dépôt, remplacement, consultation et écart d'un relevé de compte ; droits, gel, plafonds, doublons, atomicité. */
final class StatementServiceTest extends AccountingTestCase
{
    private string $upload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->upload = sys_get_temp_dir() . '/rb-acc-up-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->upload);
        parent::tearDown();
    }

    private function pdf(string $marker = 'a'): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Marker ({$marker}) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /** @param array<string, mixed> $input */
    private function deposit(int $yearId, array $input = [], ?string $content = null, string $name = 'releve.pdf', ?User $actor = null): \App\Accounting\Entity\Statement
    {
        $content ??= $this->pdf();
        file_put_contents($this->upload, $content);

        return $this->statements->deposit($actor ?? $this->admin, $yearId, $input + ['periodStart' => '2025-10-01', 'periodEnd' => '2025-10-31', 'closingBalance' => '120,00'], $this->upload, $name, strlen($content), $this->clock);
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM accounting_statements')->fetchColumn();
    }

    private function filesOnDisk(): int
    {
        return count(glob($this->statementDir . '/*') ?: []);
    }

    #[Test]
    public function testAnAdministratorDepositsAStatementAndItsFileIsKept(): void
    {
        $year = $this->openYear();

        $statement = $this->deposit($year);

        self::assertSame($year, $statement->fiscalYearId);
        self::assertSame(12000, $statement->closingBalanceCents);
        self::assertSame('application/pdf', $statement->mime);
        self::assertFileExists($this->statements->pathOf($this->admin, $statement->id));
        self::assertSame($statement->id, $this->statements->download($this->admin, $statement->id)->id);
        self::assertSame(1, $this->rows());
    }

    #[Test]
    public function testTheBalanceOnTheStatementIsOptional(): void
    {
        $statement = $this->deposit($this->openYear(), ['closingBalance' => '']);

        self::assertNull($statement->closingBalanceCents);
    }

    #[Test]
    public function testNonAdministratorsCannotDepositListOrDownloadAndNothingIsWritten(): void
    {
        $year = $this->openYear();
        $statement = $this->deposit($year);
        $this->pdo->exec('DELETE FROM accounting_statements');
        foreach ([$this->musician, $this->gone] as $actor) {
            try {
                $this->deposit($year, [], $this->pdf('b'), 'x.pdf', $actor);
                self::fail('refus attendu');
            } catch (AccessDeniedException) {
            }
            foreach ([fn () => $this->statements->review($actor, $year), fn () => $this->statements->download($actor, $statement->id)] as $call) {
                try {
                    $call();
                    self::fail('refus attendu');
                } catch (AccessDeniedException) {
                }
            }
        }
        self::assertSame(0, $this->rows());
        self::assertSame(1, $this->filesOnDisk(), 'seul le fichier du premier dépôt reste, aucun autre n\'a été écrit');
    }

    #[Test]
    public function testThePeriodMustLieInTheYearAndEndAfterItStarts(): void
    {
        $year = $this->openYear();
        foreach ([
            ['periodStart' => '2025-09-30', 'periodEnd' => '2025-10-31'],
            ['periodStart' => '2026-09-01', 'periodEnd' => '2026-10-01'],
            ['periodStart' => '2025-11-02', 'periodEnd' => '2025-11-01'],
            ['periodStart' => 'hier', 'periodEnd' => ''],
        ] as $period) {
            try {
                $this->deposit($year, $period);
                self::fail('refus attendu');
            } catch (AccountingValidationException $e) {
                self::assertNotSame([], $e->fields());
            }
        }
        self::assertSame(0, $this->rows());
        self::assertSame(0, $this->filesOnDisk());
    }

    #[Test]
    public function testABadBalanceIsRefusedByField(): void
    {
        try {
            $this->deposit($this->openYear(), ['closingBalance' => '1e3']);
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('closingBalance', $e->fields());
        }
    }

    #[Test]
    public function testAnUnknownYearIs404AndAClosedYearIsLocked(): void
    {
        $year = $this->openYear();
        $this->years->close($this->admin, $year, 0, $this->clock);

        $this->expectException(FiscalYearLockedException::class);
        $this->deposit($year);
    }

    #[Test]
    public function testDepositingInAnUnknownYearIsNotFound(): void
    {
        $this->expectException(\App\Accounting\Exception\FiscalYearNotFoundException::class);
        $this->deposit(99999999);
    }

    #[Test]
    public function testTheSameFileTwiceInAYearIsADuplicate(): void
    {
        $year = $this->openYear();
        $this->deposit($year);

        try {
            $this->deposit($year, ['periodStart' => '2025-11-01', 'periodEnd' => '2025-11-30']);
            self::fail('doublon attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('file', $e->fields());
        }
        self::assertSame(1, $this->rows());
        self::assertSame(1, $this->filesOnDisk(), 'la copie du doublon est retirée');
    }

    #[Test]
    public function testADatabaseFailureAfterTheCopyLeavesNoFileBehind(): void
    {
        $year = $this->openYear();
        $this->pdo->exec('ALTER TABLE accounting_statements RENAME TO accounting_statements_off');
        try {
            $this->deposit($year);
            self::fail('échec attendu');
        } catch (\Throwable) {
        } finally {
            $this->pdo->exec('ALTER TABLE accounting_statements_off RENAME TO accounting_statements');
        }
        self::assertSame(0, $this->filesOnDisk());
    }

    #[Test]
    public function testAReplacementKeepsTheOldStatementMarkedReplaced(): void
    {
        $year = $this->openYear();
        $old = $this->deposit($year);

        $new = $this->deposit($year, ['replaces' => (string) $old->id], $this->pdf('corrigé'));

        self::assertSame($new->id, $this->statements->find($this->admin, $old->id)->replacedById);
        self::assertFileExists($this->statements->pathOf($this->admin, $old->id), 'l\'ancien fichier est conservé');
        self::assertSame(2, $this->rows());
        try {
            $this->deposit($year, ['replaces' => (string) $old->id], $this->pdf('encore'));
            self::fail('on ne remplace pas deux fois');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('replaces', $e->fields());
        }
        self::assertSame(2, $this->rows());
        self::assertSame(2, $this->filesOnDisk());
    }

    #[Test]
    public function testOnlyAStatementOfTheSameYearCanBeReplaced(): void
    {
        $other = $this->openYear('2024-2025', '2024-10-01', '2025-09-30');
        $this->years->close($this->admin, $other, 0, $this->clock);
        $foreign = $this->statementInClosedYear($other);
        $year = $this->openYear();

        try {
            $this->deposit($year, ['replaces' => (string) $foreign], $this->pdf('x'));
            self::fail('refus attendu');
        } catch (AccountingValidationException $e) {
            self::assertArrayHasKey('replaces', $e->fields());
        }
        foreach (['0', '-1', 'abc', '1e0', '99999999'] as $id) {
            try {
                $this->deposit($year, ['replaces' => $id], $this->pdf('y' . $id));
                self::fail('refus attendu');
            } catch (AccountingValidationException $e) {
                self::assertArrayHasKey('replaces', $e->fields(), $id);
            }
        }
    }

    private function statementInClosedYear(int $yearId): int
    {
        $this->pdo->prepare('INSERT INTO accounting_statements (fiscal_year_id, period_start, period_end, original_name, stored_name, mime, size_bytes, sha256, uploaded_by_user_id, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$yearId, '2024-10-01', '2024-10-31', 'old.pdf', str_repeat('a', 32), 'application/pdf', 10, str_repeat('b', 64), $this->admin->id(), '2025-01-01 00:00:00']);

        return (int) $this->pdo->lastInsertId();
    }

    #[Test]
    public function testTheYearIsCappedInNumberOfStatementsAndInVolume(): void
    {
        $year = $this->openYear();
        $insert = $this->pdo->prepare('INSERT INTO accounting_statements (fiscal_year_id, period_start, period_end, original_name, stored_name, mime, size_bytes, sha256, uploaded_by_user_id, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($i = 0; $i < \App\Accounting\Service\StatementService::MAX_PER_YEAR; ++$i) {
            $insert->execute([$year, '2025-10-01', '2025-10-31', 'f.pdf', sprintf('%032x', $i), 'application/pdf', 10, sprintf('%064x', $i), $this->admin->id(), '2025-11-01 00:00:00']);
        }

        try {
            $this->deposit($year);
            self::fail('plafond attendu');
        } catch (\App\Group\Exception\StorageQuotaExceededException) {
        }
        self::assertSame(0, $this->filesOnDisk());
    }

    #[Test]
    public function testTheGapComparesTheStatementBalanceToTheBankBalanceOfTheLedger(): void
    {
        $year = $this->openYear(opening: '100,00');
        $this->record($year, ['occurredOn' => '2025-10-10', 'amount' => '20,00', 'paymentMethod' => 'virement']);
        $this->record($year, ['occurredOn' => '2025-10-12', 'amount' => '5,00', 'paymentMethod' => 'especes']);
        $this->record($year, ['occurredOn' => '2025-11-02', 'amount' => '50,00', 'paymentMethod' => 'virement']);
        $this->expense($year, '30,00');
        $this->deposit($year, ['closingBalance' => '120,00']);
        $this->deposit($year, ['periodStart' => '2025-11-01', 'periodEnd' => '2025-11-30', 'closingBalance' => '150,00'], $this->pdf('nov'));
        $this->deposit($year, ['periodStart' => '2025-12-01', 'periodEnd' => '2025-12-31', 'closingBalance' => ''], $this->pdf('dec'));

        [$oct, $nov, $dec] = $this->statements->review($this->admin, $year);

        self::assertSame(12000, $oct->ledgerBalanceCents, '100 + 20 virement ; l\'espèce n\'est pas en banque ; novembre pas encore');
        self::assertSame(0, $oct->gapCents);
        self::assertSame(14000, $nov->ledgerBalanceCents, '100 + 20 + 50 − 30 de loyer (virement du 5/11)');
        self::assertSame(1000, $nov->gapCents, 'relevé 150 − livre 140');
        self::assertNull($dec->gapCents, 'sans solde saisi, pas d\'écart');
    }

    #[Test]
    public function testDownloadingAnUnknownStatementOrOneWhoseFileIsMissingIsNotFound(): void
    {
        $statement = $this->deposit($this->openYear());
        unlink($this->statements->pathOf($this->admin, $statement->id));

        $refused = 0;
        foreach ([99999999, $statement->id] as $id) {
            try {
                $this->statements->download($this->admin, $id);
            } catch (StatementNotFoundException) {
                ++$refused;
            }
        }
        self::assertSame(2, $refused);
    }

    #[Test]
    public function testTheLogReceivesIdentifiersNeverTheNameOrTheBalance(): void
    {
        $this->deposit($this->openYear(), [], null, 'relevé-FR76-3000-secret.pdf');

        $log = implode("\n", $this->logged);
        self::assertStringContainsString('dépôt', $log);
        self::assertStringNotContainsString('FR76', $log);
        self::assertStringNotContainsString('12000', $log);
    }

    #[Test]
    public function testAHostileOriginalNameIsStoredCleanedAndNeverBuildsAPath(): void
    {
        $statement = $this->deposit($this->openYear(), [], null, "../../etc/pass\r\nwd\u{202E}.pdf");

        self::assertSame('passwd.pdf', $statement->originalName);
        self::assertDoesNotMatchRegularExpression('/[\x00-\x1F\x{202E}\/\\\\]/u', $statement->originalName);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $statement->storedName);
        $long = $this->deposit($statement->fiscalYearId, ['periodStart' => '2025-11-01', 'periodEnd' => '2025-11-30'], $this->pdf('long'), str_repeat('é', 400) . '.pdf');
        self::assertLessThanOrEqual(150, mb_strlen($long->originalName));
        self::assertStringEndsWith('.pdf', $long->originalName);
    }
}
