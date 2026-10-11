<?php

declare(strict_types=1);

namespace App\Account\Repository;

/** Évènements comptés par les limites par adresse (empreinte du sujet, jamais l'adresse en clair), pour SubjectThrottle. */
interface ThrottleEventRepositoryInterface
{
    /** @param string|null $scopeHash empreinte d'un sujet « parent » (l'identifiant d'un couple adresse/identifiant) : l'oublier oublie aussi cet évènement */
    public function record(string $subjectHash, \DateTimeImmutable $occurredAt, ?string $scopeHash = null): void;

    public function countSince(string $subjectHash, \DateTimeImmutable $since): int;

    public function purgeBefore(\DateTimeImmutable $before): void;

    /** Date du $n-ième évènement le plus récent depuis $since (le 1er est le dernier) ; null s'il y en a moins de $n. */
    public function nthMostRecentSince(string $subjectHash, int $n, \DateTimeImmutable $since): ?\DateTimeImmutable;

    /** Oublie tous les évènements d'un sujet, et ceux qui lui sont rattachés comme portée (connexion réussie, déblocage par un administrateur). */
    public function forget(string $subjectHash): void;
}
