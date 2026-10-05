<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/** Échecs de connexion par adresse (empreinte, jamais l'adresse en clair), pour la limite par IP (#218). */
interface LoginFailureRepositoryInterface
{
    public function record(string $ipHash, \DateTimeImmutable $failedAt): void;

    public function countSince(string $ipHash, \DateTimeImmutable $since): int;

    public function purgeBefore(\DateTimeImmutable $before): void;
}
