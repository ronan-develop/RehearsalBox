<?php

declare(strict_types=1);

namespace App\Repository;

use App\Repository\Contract\GroupImpactRepositoryInterface;

final class MysqlGroupImpactRepository implements GroupImpactRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function countsByGroup(): array
    {
        $rows = $this->pdo->query(
            'SELECT g.id,
                    (SELECT COUNT(*) FROM group_user gu WHERE gu.group_id = g.id) AS members,
                    (SELECT COUNT(*) FROM conversations c WHERE c.initiator_group_id = g.id OR c.target_group_id = g.id) AS conversations,
                    (SELECT COUNT(*) FROM group_documents d WHERE d.group_id = g.id) AS documents,
                    (SELECT COUNT(*) FROM slot_exceptions e WHERE e.requested_by_group_id = g.id) AS requests
             FROM `groups` g'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = [
                'members' => (int) $row['members'],
                'conversations' => (int) $row['conversations'],
                'documents' => (int) $row['documents'],
                'requests' => (int) $row['requests'],
            ];
        }

        return $counts;
    }
}
