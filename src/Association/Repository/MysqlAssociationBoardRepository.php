<?php

declare(strict_types=1);

namespace App\Association\Repository;

use App\Assembly\Entity\BoardPost;
use App\Association\Entity\BoardHolder;

final class MysqlAssociationBoardRepository implements AssociationBoardRepositoryInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function all(): array
    {
        $holders = [];
        foreach ($this->pdo->query('SELECT post, user_id, full_name FROM association_board')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $post = BoardPost::tryFrom((string) $row['post']);
            if ($post !== null) {
                $holders[$post->value] = new BoardHolder($post, (int) $row['user_id'], (string) $row['full_name']);
            }
        }

        return $holders;
    }

    public function assign(BoardPost $post, int $userId, string $fullName, int $actorId, \DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO association_board (post, user_id, full_name, updated_by_user_id, updated_at)
             VALUES (:post, :user_id, :full_name, :actor, :now)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), full_name = VALUES(full_name),
                                     updated_by_user_id = VALUES(updated_by_user_id), updated_at = VALUES(updated_at)'
        )->execute([
            'post' => $post->value,
            'user_id' => $userId,
            'full_name' => $fullName,
            'actor' => $actorId,
            'now' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function vacate(BoardPost $post): void
    {
        $this->pdo->prepare('DELETE FROM association_board WHERE post = :post')->execute(['post' => $post->value]);
    }
}
