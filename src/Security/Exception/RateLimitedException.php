<?php

declare(strict_types=1);

namespace App\Security\Exception;

/** Trop d'actions de la même personne dans la fenêtre (#428) : la réponse (423) annonce le délai avant de pouvoir réessayer. */
final class RateLimitedException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
