<?php

declare(strict_types=1);

namespace App\Database;

final class ConnectionFactory
{
    /** @param array{host: string, port: string, name: string, user: string, password: string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function create(): \PDO
    {
        $c = $this->config;

        return new \PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4",
            $c['user'],
            $c['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}
