<?php

declare(strict_types=1);

namespace App\Database;

final class TransactionRunner
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** @template T @param callable(): T $callback @return T */
    public function run(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
