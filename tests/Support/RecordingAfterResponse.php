<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Http\AfterResponseInterface;

/** Faux « après la réponse » de test : mémorise les tâches sans les exécuter ; `runAll()` joue ce qu'a fait le front controller. */
final class RecordingAfterResponse implements AfterResponseInterface
{
    /** @var list<callable(): void> */
    public array $tasks = [];

    public function defer(callable $task): void
    {
        $this->tasks[] = $task;
    }

    public function runAll(): void
    {
        $tasks = $this->tasks;
        $this->tasks = [];
        foreach ($tasks as $task) {
            $task();
        }
    }
}
