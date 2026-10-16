<?php

declare(strict_types=1);

namespace App\Accounting\Repository;

use App\Accounting\Entity\Member;

final class MysqlMemberDuesRepository implements MemberDuesRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function isActiveMember(int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id AND is_active = 1');
        $statement->execute(['id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function listActive(int $limit): array
    {
        $statement = $this->pdo->query('SELECT id, display_name FROM users WHERE is_active = 1 ORDER BY display_name, id LIMIT ' . max(1, $limit));

        return $statement === false ? [] : array_map(
            static fn (array $row): Member => new Member((int) $row['id'], (string) $row['display_name']),
            $statement->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    public function setExemption(int $userId, \DateTimeImmutable $month, bool $exempt, int $actorId, \DateTimeImmutable $now): void
    {
        if ($exempt) {
            $this->pdo->prepare('INSERT IGNORE INTO accounting_member_exemptions (member_user_id, period_month, created_by_user_id, created_at) VALUES (:member, :month, :actor, :now)')
                ->execute(['member' => $userId, 'month' => $month->format('Y-m-d'), 'actor' => $actorId, 'now' => $now->format('Y-m-d H:i:s')]);

            return;
        }
        $this->pdo->prepare('DELETE FROM accounting_member_exemptions WHERE member_user_id = :member AND period_month = :month')
            ->execute(['member' => $userId, 'month' => $month->format('Y-m-d')]);
    }

    public function exemptions(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $statement = $this->pdo->prepare('SELECT member_user_id, period_month FROM accounting_member_exemptions WHERE period_month BETWEEN :from AND :to LIMIT 20000');
        $statement->execute(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]);
        $found = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $found[$row['member_user_id'] . '|' . $row['period_month']] = true;
        }

        return $found;
    }
}
