<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MemberSuggestion;
use App\Repository\Contract\MemberDirectoryInterface;

final class MysqlMemberDirectory implements MemberDirectoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function search(string $query, int $exceptUserId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, display_name FROM users
             WHERE is_active = 1 AND id <> :except_user AND display_name LIKE :pattern ESCAPE '\\\\'
             ORDER BY display_name, id
             LIMIT " . max(1, $limit)
        );
        $statement->execute(['except_user' => $exceptUserId, 'pattern' => '%' . addcslashes($query, '\\%_') . '%']);
        $users = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if ($users === []) {
            return [];
        }

        $groups = $this->groupsOf(array_map(static fn (array $row): int => (int) $row['id'], $users));

        return array_map(
            static fn (array $row): MemberSuggestion => new MemberSuggestion(
                (int) $row['id'],
                (string) $row['display_name'],
                array_keys($groups[(int) $row['id']] ?? []),
                array_values($groups[(int) $row['id']] ?? []),
            ),
            $users,
        );
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, array<int, string>> pour chaque personne : identifiant du groupe => nom, par nom de groupe
     */
    private function groupsOf(array $userIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT gu.user_id, g.id, g.name FROM group_user gu JOIN `groups` g ON g.id = gu.group_id
             WHERE gu.user_id IN ({$placeholders}) ORDER BY g.name, g.id"
        );
        $statement->execute($userIds);

        $result = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['user_id']][(int) $row['id']] = (string) $row['name'];
        }

        return $result;
    }
}
