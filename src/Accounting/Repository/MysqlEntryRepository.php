<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\AccountingEntry;
use App\Accounting\Entity\Direction;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\LedgerTotals;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Service\EntryDraft;

final class MysqlEntryRepository implements EntryRepositoryInterface
{
    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(int $fiscalYearId, int $number, EntryDraft $draft, ?int $reversesEntryId, int $actorId, \DateTimeImmutable $now): int
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO accounting_entries
                    (fiscal_year_id, entry_number, occurred_on, label, amount_cents, direction, category, payment_method, voucher_ref, note,
                     reverses_entry_id, created_by_user_id, created_at)
                 VALUES (:year, :number, :occurred_on, :label, :amount, :direction, :category, :method, :voucher, :note, :reverses, :actor, :now)'
            )->execute([
                'year' => $fiscalYearId,
                'number' => $number,
                'occurred_on' => $draft->occurredOn->format('Y-m-d'),
                'label' => $draft->label,
                'amount' => $draft->amountCents,
                'direction' => $draft->direction->value,
                'category' => $draft->category->value,
                'method' => $draft->paymentMethod->value,
                'voucher' => $draft->voucherRef,
                'note' => $draft->note,
                'reverses' => $reversesEntryId,
                'actor' => $actorId,
                'now' => $now->format('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException $e) {
            if ($reversesEntryId !== null && ($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY && str_contains($e->getMessage(), 'uq_entries_reverses')) {
                throw new AccountingValidationException(['entry' => 'Cette écriture est déjà annulée.']);
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?AccountingEntry
    {
        $statement = $this->pdo->prepare('SELECT * FROM accounting_entries WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    public function reversalOf(int $id): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM accounting_entries WHERE reverses_entry_id = :id');
        $statement->execute(['id' => $id]);
        $found = $statement->fetchColumn();

        return $found === false ? null : (int) $found;
    }

    public function listByYear(int $fiscalYearId, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM accounting_entries WHERE fiscal_year_id = :year ORDER BY entry_number LIMIT :limit');
        $statement->bindValue('year', $fiscalYearId, \PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, min($limit, 1000)), \PDO::PARAM_INT);
        $statement->execute();

        return array_map($this->hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function totals(int $fiscalYearId): LedgerTotals
    {
        $statement = $this->pdo->prepare(
            'SELECT category, direction, SUM(amount_cents) AS total FROM accounting_entries WHERE fiscal_year_id = :year GROUP BY category, direction'
        );
        $statement->execute(['year' => $fiscalYearId]);
        $recettes = 0;
        $depenses = 0;
        $net = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $total = (int) $row['total'];
            $isRecette = $row['direction'] === Direction::Recette->value;
            $isRecette ? $recettes += $total : $depenses += $total;
            $net[(string) $row['category']] = ($net[(string) $row['category']] ?? 0) + ($isRecette ? $total : -$total);
        }

        return new LedgerTotals($recettes, $depenses, $net);
    }

    public function bankNetUntil(int $fiscalYearId, \DateTimeImmutable $date): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(CASE direction WHEN 'recette' THEN amount_cents ELSE -CAST(amount_cents AS SIGNED) END), 0)
             FROM accounting_entries WHERE fiscal_year_id = :year AND payment_method <> 'especes' AND occurred_on <= :date"
        );
        $statement->execute(['year' => $fiscalYearId, 'date' => $date->format('Y-m-d')]);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): AccountingEntry
    {
        return new AccountingEntry(
            (int) $row['id'],
            (int) $row['fiscal_year_id'],
            (int) $row['entry_number'],
            new \DateTimeImmutable((string) $row['occurred_on']),
            (string) $row['label'],
            (int) $row['amount_cents'],
            Direction::from((string) $row['direction']),
            EntryCategory::from((string) $row['category']),
            PaymentMethod::from((string) $row['payment_method']),
            $row['voucher_ref'] === null ? null : (string) $row['voucher_ref'],
            $row['note'] === null ? null : (string) $row['note'],
            $row['reverses_entry_id'] === null ? null : (int) $row['reverses_entry_id'],
        );
    }
}
