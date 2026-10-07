<?php

declare(strict_types=1);

namespace App\Account\Repository;

/** Évènements comptés par les limites par adresse (empreinte du sujet, jamais l'adresse en clair), pour IpThrottle. */
interface ThrottleEventRepositoryInterface
{
    public function record(string $subjectHash, \DateTimeImmutable $occurredAt): void;

    public function countSince(string $subjectHash, \DateTimeImmutable $since): int;

    public function purgeBefore(\DateTimeImmutable $before): void;
}
