<?php

declare(strict_types=1);

namespace App\Tests\Doubles;

use Psr\Log\AbstractLogger;

/** Journal de test : garde les évènements en mémoire pour vérifier ce qui est (et ce qui n'est pas) journalisé. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** Tout ce qui a été journalisé, en une seule chaîne (niveau, message, contexte en JSON) : pratique pour les assertions de contenu. */
    public function text(): string
    {
        return implode("\n", array_map(
            static fn (array $r): string => $r['level'] . ' ' . $r['message'] . ' ' . json_encode($r['context']),
            $this->records,
        ));
    }
}
