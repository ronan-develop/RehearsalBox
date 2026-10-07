<?php

declare(strict_types=1);

namespace App\Planning\Repository;

use App\Planning\Repository\BookingDateLockInterface;

/** GET_LOCK / RELEASE_LOCK de MariaDB : un verrou nommé par date, rattaché à la connexion. */
final class MysqlBookingDateLock implements BookingDateLockInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function acquire(\DateTimeImmutable $date, int $waitSeconds = 5): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, :wait)');
        $statement->execute(['name' => $this->name($date), 'wait' => $waitSeconds]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function release(\DateTimeImmutable $date): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => $this->name($date)]);
    }

    private function name(\DateTimeImmutable $date): string
    {
        return 'rb-booking-' . $date->format('Y-m-d');
    }
}
