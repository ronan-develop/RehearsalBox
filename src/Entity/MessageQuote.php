<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Message cité par une réponse (#214) : seul l'identifiant est stocké, auteur et texte sont relus à l'affichage (un message
 * corrigé apparaît donc corrigé dans la citation). L'aperçu est court, sur une seule ligne.
 */
final class MessageQuote
{
    public const EXCERPT_LENGTH = 100;

    public function __construct(
        private readonly int $messageId,
        private readonly string $authorName,
        private readonly string $body,
    ) {
    }

    public function messageId(): int
    {
        return $this->messageId;
    }

    public function authorName(): string
    {
        return $this->authorName;
    }

    /** Début du texte cité : une seule ligne, coupé à EXCERPT_LENGTH caractères (jamais au milieu d'un caractère). */
    public function excerpt(): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->body));

        return mb_strlen($text) > self::EXCERPT_LENGTH ? mb_substr($text, 0, self::EXCERPT_LENGTH) . '…' : $text;
    }
}
