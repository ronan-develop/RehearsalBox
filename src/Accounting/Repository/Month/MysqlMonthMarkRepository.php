<?php

declare(strict_types=1);

namespace App\Accounting\Repository\Month;

final class MysqlMonthMarkRepository implements MonthMarkRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function mark(int $fiscalYearId, \DateTimeImmutable $month, int $actorId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare('INSERT IGNORE INTO accounting_month_marks (fiscal_year_id, month, created_by_user_id, created_at) VALUES (:year, :month, :actor, :now)')
            ->execute(['year' => $fiscalYearId, 'month' => $month->format('Y-m-d'), 'actor' => $actorId, 'now' => $now->format('Y-m-d H:i:s')]);
    }

    public function marked(int $fiscalYearId): array
    {
        $statement = $this->pdo->prepare('SELECT month FROM accounting_month_marks WHERE fiscal_year_id = :year LIMIT 100');
        $statement->execute(['year' => $fiscalYearId]);
        $marked = [];
        foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $month) {
            $marked[substr((string) $month, 0, 7)] = true;
        }

        return $marked;
    }
}
