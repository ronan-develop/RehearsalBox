<?php

declare(strict_types=1);

namespace App\Repository\Contract;

/** Anciennes versions des messages corrigés (#200) : conservées pour l'audit, jamais affichées, oubliées après 30 jours (#225). */
interface MessageVersionRepositoryInterface
{
    /**
     * Anciennes versions d'un message, la plus ancienne d'abord.
     *
     * @return list<array{body: string, savedAt: \DateTimeImmutable}>
     */
    public function versionsOf(int $messageId): array;

    /**
     * Supprime les versions enregistrées avant $cutoff (le texte courant d'un message n'est jamais touché).
     *
     * @return int nombre de versions supprimées
     */
    public function purgeBefore(\DateTimeImmutable $cutoff): int;
}
