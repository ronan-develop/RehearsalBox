<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Travail à faire APRÈS que la réponse est partie chez le client. Sert quand le temps de réponse ne doit rien révéler
 * (ex. mot de passe oublié : le travail diffère selon que le compte existe ou non) ou quand l'envoi d'un e-mail ne doit
 * pas faire attendre. Une tâche qui échoue ne casse jamais la requête.
 */
interface AfterResponseInterface
{
    /** @param callable(): void $task */
    public function defer(callable $task): void;
}
