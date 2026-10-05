<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Collecte les tâches pendant la requête ; `run()` (appelé par le front controller une fois la réponse envoyée) libère
 * d'abord le client (`$finishResponse`) puis exécute les tâches dans l'ordre. Une exception est absorbée et seule sa CLASSE
 * est journalisée : jamais de message (il pourrait contenir une adresse ou un jeton).
 */
final class DeferredAfterResponse implements AfterResponseInterface
{
    /** @var list<callable(): void> */
    private array $tasks = [];

    /** @param callable(): void $finishResponse ferme la connexion avec le client sans arrêter le script (voir finishRequest()) */
    public function __construct(private readonly mixed $finishResponse)
    {
    }

    /**
     * Libère le client dès que le serveur sait le faire : PHP-FPM (`fastcgi_finish_request`) ou LiteSpeed
     * (`litespeed_finish_request`). Sans l'un des deux (serveur de développement), le travail suit la réponse sans la couper.
     */
    public static function finishRequest(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
    }

    public function run(): void
    {
        if ($this->tasks === []) {
            return;
        }
        $tasks = $this->tasks;
        $this->tasks = [];

        ($this->finishResponse)();
        foreach ($tasks as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                error_log(sprintf('Tâche après réponse : échec (%s).', $e::class));
            }
        }
    }
}
