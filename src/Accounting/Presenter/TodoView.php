<?php

declare(strict_types=1);

namespace App\Accounting\Presenter;

use App\Accounting\Todo\TodoItem;
use App\Accounting\Todo\TodoState;

/** Données de la liste « À faire » (#362) : texte brut (le gabarit échappe), les plus urgentes d'abord, au plus `LIMIT` affichées. */
final class TodoView
{
    public const LIMIT = 8;

    /**
     * @param list<TodoItem> $items
     * @return array{items: list<array<string, mixed>>, hidden: int, lateCount: int, total: int}
     */
    public function build(array $items, int $yearId): array
    {
        $rows = [];
        foreach (array_slice($items, 0, self::LIMIT) as $item) {
            $rows[] = [
                'key' => $item->key,
                'label' => $item->label,
                'action' => $item->action,
                'url' => $item->url,
                'state' => $item->state->value,
                'stateLabel' => $item->state->label(),
                'late' => $item->state === TodoState::EnRetard,
                'due' => $item->dueOn === null ? '' : 'avant le ' . $item->dueOn->format('d/m/Y'),
                'emptyMonth' => str_starts_with($item->key, 'entries:') ? substr($item->key, 8) : '',
            ];
        }

        return [
            'items' => $rows,
            'hidden' => max(0, count($items) - self::LIMIT),
            'lateCount' => count(array_filter($items, static fn (TodoItem $i): bool => $i->state === TodoState::EnRetard)),
            'total' => count($items),
        ];
    }
}
