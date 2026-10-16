<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\FiscalYearStatus;
use App\Accounting\Exception\AccountingValidationException;

class MysqlFiscalYearRepository implements FiscalYearRepositoryInterface
{
    private const DATE_TIME = 'Y-m-d H:i:s';

    private const MYSQL_DUPLICATE_KEY = 1062;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(string $label, \DateTimeImmutable $startsOn, \DateTimeImmutable $endsOn, int $openingBalanceCents, int $actorId, \DateTimeImmutable $now): int
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO accounting_fiscal_years (label, starts_on, ends_on, opening_balance_cents, created_by_user_id, created_at)
                 VALUES (:label, :starts_on, :ends_on, :opening, :actor, :now)'
            )->execute([
                'label' => $label,
                'starts_on' => $startsOn->format('Y-m-d'),
                'ends_on' => $endsOn->format('Y-m-d'),
                'opening' => $openingBalanceCents,
                'actor' => $actorId,
                'now' => $now->format(self::DATE_TIME),
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === self::MYSQL_DUPLICATE_KEY) {
                throw new AccountingValidationException(['label' => 'Un exercice porte déjà ce nom.']);
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?FiscalYear
    {
        return $this->one('SELECT * FROM accounting_fiscal_years WHERE id = :id', ['id' => $id]);
    }

    public function lock(int $id): ?FiscalYear
    {
        return $this->one('SELECT * FROM accounting_fiscal_years WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function lockAll(): void
    {
        $this->pdo->query('SELECT id FROM accounting_fiscal_years ORDER BY id FOR UPDATE')->fetchAll();
    }

    public function listAll(): array
    {
        $rows = $this->pdo->query('SELECT * FROM accounting_fiscal_years ORDER BY starts_on DESC')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map($this->hydrate(...), $rows);
    }

    public function latest(): ?FiscalYear
    {
        return $this->one('SELECT * FROM accounting_fiscal_years ORDER BY ends_on DESC LIMIT 1', []);
    }

    public function findOpenContaining(\DateTimeImmutable $date): ?FiscalYear
    {
        return $this->one(
            "SELECT * FROM accounting_fiscal_years WHERE status = 'ouvert' AND :date BETWEEN starts_on AND ends_on LIMIT 1",
            ['date' => $date->format('Y-m-d')],
        );
    }

    public function incrementNextNumber(int $id): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE accounting_fiscal_years SET next_entry_number = next_entry_number + 1 WHERE id = :id AND status = 'ouvert'"
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() === 1;
    }

    public function close(int $id, int $expectedRevision, int $closingBalanceCents, int $actorId, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE accounting_fiscal_years
             SET status = 'cloture', closing_balance_cents = :closing, closed_by_user_id = :actor, closed_at = :now, revision = revision + 1
             WHERE id = :id AND status = 'ouvert' AND revision = :revision"
        );
        $statement->execute(['closing' => $closingBalanceCents, 'actor' => $actorId, 'now' => $now->format(self::DATE_TIME), 'id' => $id, 'revision' => $expectedRevision]);

        return $statement->rowCount() === 1;
    }

    public function approve(int $id, \DateTimeImmutable $approvedOn, int $actorId, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE accounting_fiscal_years
             SET status = 'approuve', approved_on = :approved_on, approved_by_user_id = :actor, approved_at = :now, revision = revision + 1
             WHERE id = :id AND status = 'cloture'"
        );
        $statement->execute(['approved_on' => $approvedOn->format('Y-m-d'), 'actor' => $actorId, 'now' => $now->format(self::DATE_TIME), 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $params */
    private function one(string $sql, array $params): ?FiscalYear
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FiscalYear
    {
        return new FiscalYear(
            (int) $row['id'],
            (string) $row['label'],
            new \DateTimeImmutable((string) $row['starts_on']),
            new \DateTimeImmutable((string) $row['ends_on']),
            FiscalYearStatus::from((string) $row['status']),
            (int) $row['opening_balance_cents'],
            $row['closing_balance_cents'] === null ? null : (int) $row['closing_balance_cents'],
            (int) $row['next_entry_number'],
            $row['approved_on'] === null ? null : new \DateTimeImmutable((string) $row['approved_on']),
            (int) $row['revision'],
        );
    }
}
