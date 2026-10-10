<?php

declare(strict_types=1);

namespace App\Messaging\Crypto;

/** Chiffrement au repos du texte de la messagerie (#171) : ce qui est écrit en base est chiffré, tout le reste du code ne voit que le clair. */
interface MessageCipher
{
    /** Valeur à stocker en base (jamais le clair). */
    public function encrypt(string $plain): string;

    /**
     * @throws MessageCipherException valeur altérée, mauvaise ou inconnue clé, ou texte non chiffré hors période de transition
     */
    public function decrypt(string $stored): string;

    /** A la forme d'une valeur chiffrée (n'importe quelle clé du trousseau) : faux pour du clair d'avant le chiffrement. */
    public function isEncrypted(string $stored): bool;

    /** Chiffrée avec la clé courante : faux pour du clair ou pour une ancienne clé (le rattrapage et la rotation la réécrivent). */
    public function isCurrent(string $stored): bool;
}
