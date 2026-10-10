<?php

declare(strict_types=1);

namespace App\Account\Repository;

/** Évènements comptés par les limites par adresse (empreinte du sujet, jamais l'adresse en clair), pour SubjectThrottle. */
interface ThrottleEventRepositoryInterface
{
    public function record(string $subjectHash, \DateTimeImmutable $occurredAt): void;

    public function countSince(string $subjectHash, \DateTimeImmutable $since): int;

    public function purgeBefore(\DateTimeImmutable $before): void;

    /** Date du $n-ième évènement le plus récent depuis $since (le 1er est le dernier) ; null s'il y en a moins de $n. */
    public function nthMostRecentSince(string $subjectHash, int $n, \DateTimeImmutable $since): ?\DateTimeImmutable;

    /** Oublie tous les évènements d'un sujet (connexion réussie, déblocage par un administrateur). */
    public function forget(string $subjectHash): void;
}
