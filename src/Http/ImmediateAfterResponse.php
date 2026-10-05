<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Exécute la tâche tout de suite (scripts en ligne de commande, tests) : pas de réponse à libérer. Même règle d'erreur que
 * DeferredAfterResponse : une tâche qui échoue ne remonte jamais.
 */
final class ImmediateAfterResponse implements AfterResponseInterface
{
    public function defer(callable $task): void
    {
        $deferred = new DeferredAfterResponse(static function (): void {
        });
        $deferred->defer($task);
        $deferred->run();
    }
}
