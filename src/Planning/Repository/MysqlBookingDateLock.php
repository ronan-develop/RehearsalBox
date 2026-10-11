<?php

declare(strict_types=1);

namespace App\Planning\Repository;

use App\Planning\Repository\BookingDateLockInterface;

/** GET_LOCK / RELEASE_LOCK de MariaDB : un verrou nommé par date ou par groupe, rattaché à la connexion. */
final class MysqlBookingDateLock implements BookingDateLockInterface
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function acquire(\DateTimeImmutable $date, int $waitSeconds = 5): bool
    {
        return $this->lock($this->name($date), $waitSeconds);
    }

    public function release(\DateTimeImmutable $date): void
    {
        $this->unlock($this->name($date));
    }

    public function acquireGroup(int $groupId, int $waitSeconds = 5): bool
    {
        return $this->lock('rb-booking-group-' . $groupId, $waitSeconds);
    }

    public function releaseGroup(int $groupId): void
    {
        $this->unlock('rb-booking-group-' . $groupId);
    }

    private function lock(string $name, int $waitSeconds): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, :wait)');
        $statement->execute(['name' => $name, 'wait' => $waitSeconds]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function unlock(string $name): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => $name]);
    }

    private function name(\DateTimeImmutable $date): string
    {
        return 'rb-booking-' . $date->format('Y-m-d');
    }
}
