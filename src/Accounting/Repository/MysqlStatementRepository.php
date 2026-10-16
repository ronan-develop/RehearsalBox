<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\Statement;

final class MysqlStatementRepository implements StatementRepositoryInterface
{
    private const COLUMNS = 'id, fiscal_year_id, period_start, period_end, closing_balance_cents, original_name, stored_name, mime, size_bytes, sha256, replaced_by_id, uploaded_at';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function insert(int $fiscalYearId, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, ?int $closingBalanceCents, string $originalName, string $storedName, string $mime, int $sizeBytes, string $sha256, int $actorId, \DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            'INSERT INTO accounting_statements
                (fiscal_year_id, period_start, period_end, closing_balance_cents, original_name, stored_name, mime, size_bytes, sha256, uploaded_by_user_id, uploaded_at)
             VALUES (:year, :start, :end, :balance, :original, :stored, :mime, :size, :hash, :actor, :now)'
        )->execute([
            'year' => $fiscalYearId,
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
            'balance' => $closingBalanceCents,
            'original' => $originalName,
            'stored' => $storedName,
            'mime' => $mime,
            'size' => $sizeBytes,
            'hash' => $sha256,
            'actor' => $actorId,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function find(int $id): ?Statement
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM accounting_statements WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function listByYear(int $fiscalYearId, int $limit): array
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM accounting_statements WHERE fiscal_year_id = :year ORDER BY period_start, id LIMIT ' . max(1, $limit));
        $statement->execute(['year' => $fiscalYearId]);

        return array_map(self::hydrate(...), $statement->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function countByYear(int $fiscalYearId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM accounting_statements WHERE fiscal_year_id = :year');
        $statement->execute(['year' => $fiscalYearId]);

        return (int) $statement->fetchColumn();
    }

    public function totalBytesByYear(int $fiscalYearId): int
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM accounting_statements WHERE fiscal_year_id = :year');
        $statement->execute(['year' => $fiscalYearId]);

        return (int) $statement->fetchColumn();
    }

    public function hashExists(int $fiscalYearId, string $sha256): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM accounting_statements WHERE fiscal_year_id = :year AND sha256 = :hash LIMIT 1');
        $statement->execute(['year' => $fiscalYearId, 'hash' => $sha256]);

        return $statement->fetchColumn() !== false;
    }

    public function markReplaced(int $id, int $newId): bool
    {
        $statement = $this->pdo->prepare('UPDATE accounting_statements SET replaced_by_id = :new WHERE id = :id AND replaced_by_id IS NULL');
        $statement->execute(['new' => $newId, 'id' => $id]);

        return $statement->rowCount() === 1;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Statement
    {
        return new Statement(
            (int) $row['id'],
            (int) $row['fiscal_year_id'],
            new \DateTimeImmutable((string) $row['period_start']),
            new \DateTimeImmutable((string) $row['period_end']),
            $row['closing_balance_cents'] === null ? null : (int) $row['closing_balance_cents'],
            (string) $row['original_name'],
            (string) $row['stored_name'],
            (string) $row['mime'],
            (int) $row['size_bytes'],
            (string) $row['sha256'],
            $row['replaced_by_id'] === null ? null : (int) $row['replaced_by_id'],
            new \DateTimeImmutable((string) $row['uploaded_at']),
        );
    }
}
